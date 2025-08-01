# Documentation: HVAC Control Suite for IP-Symcon

This document provides a detailed overview of the `Zoning_and_Demand_Manager` and `adaptive_HVAC_control` modules, explaining their individual functions and how they collaborate to create an intelligent and efficient climate control system.

## Module 1: `Zoning_and_Demand_Manager`

### Purpose

This module acts as the **master rule engine and zone controller** for the entire HVAC system. Its primary job is to make high-level decisions about which rooms are *allowed* to receive cooling based on a clear set of rules. It is not concerned with *how much* power the AC unit should use, only with *on/off* permissions for each zone.

### Key Responsibilities

1.  **Room Status Monitoring:** For every configured room, the module continuously checks:
    *   **Temperature:** Is the current temperature significantly above the target temperature (considering a built-in hysteresis)?
    *   **Window/Door Status:** Are any windows or doors in that room open? It does this by checking a user-defined category that should contain **links** to the relevant sensor variables (which can be either Boolean or Integer types).
    *   **Local AC Mode:** Is the room's thermostat set to 'Off', 'On' (cool once), or 'Auto'?

2.  **Air Flap Control:** Based on the room status, it directly controls the motorized air flap (damper) for each zone, opening it when cooling is permitted and closing it when it's not.

3.  **System-Wide Overrides:** It checks for global "kill" signals. If a system-wide "Heating Active" or "Ventilation Active" variable is true, it will disable all cooling activity to prevent the two systems from fighting each other.

4.  **Demand Signalling:** This is its key function when used in cooperative mode. For each room, it controls a "Demand" variable (Integer).
    *   If a room needs cooling and is allowed to receive it, its Demand variable is set to `1`.
    *   If a room's target temperature is reached, or a window is open, etc., its Demand variable is set to `0`.

### Operating Modes

The module has two distinct operating modes:

*   **Standalone Mode:** In this mode, the module acts like a simple multi-zone thermostat. If any room demands cooling, it turns on the main AC unit and main fan to a **fixed, user-defined power and fan speed**. It does not require the `adaptive_HVAC_control` module. This is useful for simple setups or for testing.
*   **Cooperative (Adaptive) Mode:** This is the default and intended mode. The module only manages room permissions (flaps and demand variables) and a single on/off signal for the main AC system. It leaves the complex task of deciding the optimal power and fan speed to its partner, the `adaptive_HVAC_control` module.

---

## Module 2: `adaptive_HVAC_control`

### Purpose

This module is the **intelligent optimization engine** of the system. Its purpose is to find the most efficient **power and fan speed** to use in order to cool the active rooms without wasting energy or causing undesirable side effects (like the coil freezing). It uses a reinforcement learning technique called Q-learning.

### Core Concept: Q-Learning Explained

Think of this module as a digital brain that learns from experience.

*   **Q-Table:** This is its memory. It's a large table that stores the "quality" (Q-value) of taking a certain action in a certain situation.
*   **State:** A "situation" is the current state of the system, represented by a simple string like `5|4|0|1`. This string captures all the important information at a moment in time.
*   **Action:** An "action" is a combination of Power and Fan speed the module can choose, for example, `P:80 F:100`.
*   **Reward:** After taking an action, the module waits and observes the result. It then calculates a "reward" score.
    *   **Good results** (making progress on cooling the rooms) get a **positive reward**.
    *   **Bad results** (coil temperature dropping too low, using too much energy, overcooling rooms) get a **negative reward**.
*   **Learning:** The module updates its Q-Table using this reward. If an action in a certain state led to a good reward, the Q-value for that state-action pair increases, making it more likely to be chosen again. If it led to a bad reward, the value decreases.

### The State String (`d|c|o|t|r` or `d|c|t|r`)

The state is a snapshot of the system, discretized into "bins" for efficient learning.

*   `d` - **Demand Bin:** How hot is the hottest room? (0 = at target, 5 = very hot)
*   `c` - **Coil Safety Bin:** How close is the evaporator coil to freezing? (e.g., -2 = danger, 5 = very safe)
*   `o` - **Overcool Bin:** (Only used in Standalone mode) Is any room getting too cold? (0 = none, 3 = very overcooled)
*   `t` - **Coil Trend Bin:** Is the coil temperature currently dropping (-1), stable (0), or rising (1)?
*   `r` - **Room Count Bin:** How many rooms are demanding cooling? This is capped (e.g., at 4) to mean "4 or more rooms".

### Operating Modes

This module's behavior changes depending on the mode to optimize learning:

*   **Standalone Mode:** It is responsible for everything. It must learn not to overcool rooms. The state string includes the `o` (Overcool) bin, and it gets a negative reward for making rooms too cold.
*   **Cooperative Mode:** It knows the `Zoning_and_Demand_Manager` will prevent overcooling by closing flaps. Therefore, the `o` bin is removed from the state string to simplify the problem and speed up learning. The module can focus entirely on efficient cooling and coil safety.

---

## How They Work Together (Cooperative Mode)

This is where the magic happens. The two modules have a clear separation of concerns, leading to a robust and efficient system.

**Workflow Sequence:**

1.  A user lowers the target temperature in the "Living Room".
2.  The **`Zoning_and_Demand_Manager`** (the rule engine) detects that the Living Room's current temperature is now above the target.
3.  It checks its rules: Are any windows open? Is the heating on? Is the room's AC mode set to 'Off'?
4.  Assuming all rules pass, the `Zoning_and_Demand_Manager` performs two actions:
    *   It commands the **Living Room's air flap to open**.
    *   It sets the **Living Room's "Demand" variable to `1`**.
5.  The **`adaptive_HVAC_control`** module (the optimization engine) is monitoring all the "Demand" variables. It now sees that one room has active demand.
6.  The `adaptive_HVAC_control` module wakes up. It reads all system parameters to build its current **State** (Demand Bin, Coil Bin, Trend Bin, Room Count Bin).
7.  It consults its **Q-Table** to find the best **Action** (Power and Fan setting) for the current state.
8.  It sends the chosen power and fan commands to the main AC unit.
9.  The process repeats. If another room ("Kitchen") calls for cooling, the `Zoning_and_Demand_Manager` opens its flap and sets its Demand variable to `1`. The `adaptive_HVAC_control` module now sees a Room Count of 2 and may choose a more powerful action.
10. **Cycle End:** Eventually, the Living Room reaches its target temperature.
11. The `Zoning_and_Demand_Manager` detects this. It immediately:
    *   Commands the **Living Room's air flap to close**.
    *   Sets the **Living Room's "Demand" variable back to `0`**.
12. The `adaptive_HVAC_control` module sees the demand has disappeared (or lessened, if other rooms are still active) and recalculates the optimal strategy for the remaining rooms. If no rooms are left, it sets power and fan to 0.

This elegant partnership allows the `Zoning_and_Demand_Manager` to handle the absolute, "black and white" rules, while the `adaptive_HVAC_control` module handles the nuanced, "shades of grey" optimization problem.

---

## Configuration Details: Variables, Links & Categories

To complete this documentation, here is a detailed breakdown of every configurable parameter.

### `Zoning_and_Demand_Manager` Parameters

These are configured in the main settings page for the Zoning Manager instance.

Parameter                   | Type              | Mandatory?   | Purpose
----------------------------|-------------------|--------------|-----------------------------------------------------------------------------------------------------------------------------------------------------
**Heating Active Link**     | Link (to Boolean) | No           | An optional override. Links to a variable that is `true` when heating is active. If true, all cooling is disabled.
**Ventilation Active Link** | Link (to Boolean) | No           | An optional override. Links to a variable that is `true` when ventilation-only mode is active. If true, all cooling is disabled.
**Main Fan Control**        | Link (to Variable)| **Yes**      | Links to the variable that controls the main HVAC fan. Must be a Boolean in cooperative mode or an Integer/Float for a specific value in standalone mode.
**Main AC Unit**            | Link (to Variable)| **Yes**      | Links to the variable that controls the main AC unit/compressor. Must be a Boolean in cooperative mode or an Integer/Float for a specific value in standalone mode.
**Master Bed Special Mode** | Link (to Integer) | No           | An optional feature. If cooling is off but this link's target is > 0, the fan will turn on and the 'Master Bed Room' flap will open.
**System Status Text**      | Link (to String)  | No           | An optional link to a string variable where the module will write its current status (e.g., "Cooling Activated").

#### Per-Room Parameters (in the "Controlled Rooms" list)

Parameter                   | Type              | Mandatory?                    | Purpose
----------------------------|-------------------|-------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------
**Temp Sensor**             | Variable          | **Yes**                       | The variable holding the room's current temperature.
**Humidity Sensor**         | Variable          | No                            | Optional. The variable holding the room's current humidity (for future use).
**Target Temp**             | Variable          | **Yes**                       | The variable holding the room's desired target temperature.
**Window/Door Category**    | Category          | No                            | Optional. Select a Category that contains **Links** to all window/door sensors for the room. If any linked sensor is `true` or non-zero, cooling for this room is disabled.
**Demand Output**           | Variable          | **Yes (in Cooperative Mode)** | The Integer variable that signals this room's cooling demand (`1` for active, `0` for inactive) to the adaptive module. Not required in Standalone mode.
**Flap Control Variable**   | Variable          | **Yes**                       | The variable that controls this room's motorized air flap.
**AC Mode Input**           | Variable          | No                            | Optional. Links to the room's thermostat mode variable (1=Off, 2=On, 3=Auto). If not set, the room is always considered 'Auto'.
**Phase Info Output**       | Variable          | No                            | Optional. An Integer variable where the module writes its internal state for the room (e.g., 0=Off, 2=Auto-Cooling, 3=Window Open) for debugging/visualization.

---

### `adaptive_HVAC_control` Parameters

These are configured in the main settings page for the Adaptive Controller instance.

Parameter                   | Type              | Mandatory?   | Purpose
----------------------------|-------------------|--------------|-----------------------------------------------------------------------------------------------------------------------------------------------------
**AC Active Link**          | Link (to Boolean) | **Yes**      | The primary trigger for the module. It should link to a variable that is `true` when the system is permitted to run (typically the `MainACOnOffLink` controlled by the Zoning Manager).
**Power Output Link**       | Link (to Variable)| **Yes**      | Links to the variable (Integer/Float) that controls the AC power/compressor stage. The module sends its calculated optimal power here.
**Fan Output Link**         | Link (to Variable)| **Yes**      | Links to the variable (Integer/Float) that controls the fan speed. The module sends its calculated optimal fan speed here.
**Coil Temperature Link**   | Link (to Variable)| **Yes**      | Provides the current temperature of the evaporator coil. This is crucial for the learning algorithm's coil safety feature.
**Min Coil Temp Link**      | Link (to Variable)| **Yes**      | Provides the minimum safe operating temperature for the coil. This defines the baseline for the coil safety calculation.

#### Per-Room Parameters (in the "Monitored Rooms" list)

Parameter                 | Type              | Mandatory?                    | Purpose
--------------------------|-------------------|-------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
**Temp Sensor**           | Variable          | **Yes**           
