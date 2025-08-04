# Module: HVAC Learning Orchestrator

### Purpose

This module is the **"Conductor"** of the Adaptive HVAC Control Suite. Its sole purpose is to execute an automated, rapid **Commissioning and Self-Tuning (CST)** routine. It acts as a temporary "master" to the other HVAC modules, guiding them through a pre-defined plan to systematically explore the system's behavior.

By using this module, the `adaptive_HVAC_control` module's Q-Table can be "seeded" with high-quality, diverse data in approximately **1.5-2 hours**, a process that would otherwise take weeks of passive, weather-dependent learning.

This module is intended to be run **once** after the initial system setup, or again if major system changes occur. After the calibration is complete, it returns to an idle state, and the other modules resume their normal, optimized operation.

### How It Works

The Orchestrator operates as a **State Machine**, driven by a timer. When a calibration is started, it takes exclusive control of the other two modules:

1.  **Commands the `Zoning_and_Demand_Manager`:** It places the Zoning Manager into an "Override Mode," allowing the Orchestrator to directly control the main AC/Fan relays and all room flaps, bypassing the Zoning Manager's own temperature-based logic.
2.  **Commands the `adaptive_HVAC_control` module:** It places the Adaptive Controller into an "Orchestrated Mode," disabling its autonomous decision-making. The Orchestrator then feeds the controller a sequence of pre-defined actions (`Power:Fan` settings).
3.  **Simulates Conditions:** For each stage of its plan, the Orchestrator can temporarily override room target temperatures. By setting an artificially low target, it can simulate a high-demand cooling state (`d=5`), even on a cool day. This ensures the AI learns how to handle all levels of cooling demand.
4.  **Injects Knowledge:** After forcing the Adaptive Controller to take an action, the Orchestrator waits for the system to react, observes the outcome (new state and reward), and then commands the controller to learn from this specific experience using the `ForceActionAndLearn` API.

This process builds a rich, foundational "map" (the Q-Table) covering all critical operating points of the HVAC system.

### Configuration

#### 1. Core Module Links
*   **Link to Zoning & Demand Manager:** Select the instance of your `Zoning_and_Demand_Manager` module.
*   **Link to Adaptive HVAC Control:** Select the instance of your `adaptive_HVAC_control` module.

#### 2. Room Configuration Links
This list is essential for the advanced "Level 4" calibration. The Orchestrator needs to read the current temperature and write to the target temperature for each room to simulate different demand levels.
*   **Room Name:** The name of the room. **This must exactly match the name configured in the `Zoning_and_Demand_Manager` module.**
*   **Current Temp. Variable:** Link to the variable holding the room's current temperature.
*   **Target Temp. Variable:** Link to the variable holding the room's setpoint/target temperature.

#### 3. Automated Calibration Plan
This is the heart of the Orchestrator. It's a configurable list where each row represents a distinct testing stage. A sensible default plan is provided, but it can be fully customized.
*   **Stage Name:** A descriptive name for the test stage (e.g., "High Demand Test").
*   **Flap Config:** A comma-separated list defining the state of each room's flap for this stage. The format is `RoomName=true` for open, `RoomName=false` for closed. Room names must match the configuration in the Zoning Manager.
*   **Target Temp Offset (°C):** A negative number that will be temporarily added to each active room's *current* temperature to create an artificial target. For example, if a room is at 24°C and the offset is `-5.0`, the target will be set to 19°C, creating a high-demand state. Use a small offset like `-0.5` to test low-demand scenarios.
*   **Action Pattern:** A comma-separated list of `Power:Fan` actions (e.g., `30:30, 55:60, 75:90`) that will be executed sequentially during this stage.

### Usage Instructions

1.  Install and configure all three modules (`Zoning`, `Adaptive`, `Orchestrator`). Ensure all links are set correctly.
2.  Review the `CalibrationPlan` in the Orchestrator's configuration. Adjust room names and the plan as needed for your specific setup. Save the configuration.
3.  Click the **"Start Calibration"** button. The Orchestrator will take control, and its status will change to "Calibration is Running." The process will take approximately 1.5-2 hours, depending on your plan. You can monitor the progress via the Symcon log file.
4.  Once complete, the status will change to "Calibration is Done." The Orchestrator will automatically return the other two modules to their normal operating modes.
5.  Your HVAC system is now running with a fully-seeded, intelligent control policy.
6.  The **"Stop Calibration"** button can be used to gracefully interrupt the process at any time. It will stop the timer and return the other modules to normal operation.
