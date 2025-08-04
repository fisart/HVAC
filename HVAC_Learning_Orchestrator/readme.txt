Project Documentation: The Adaptive HVAC Control Suite (Version 3.0)
1. System Overview & Architecture
This suite provides an intelligent, self-learning climate control system for multi-zone HVAC applications within IP-Symcon. It evolves beyond simple rule-based thermostats by implementing a Reinforcement Learning agent that optimizes for both comfort and energy efficiency.
The architecture is composed of three distinct, collaborating modules:
Zoning_and_Demand_Manager ("The Body"): The hardware abstraction layer. It directly controls the physical components of the HVAC system (relays, dampers).
adaptive_HVAC_control ("The Brain"): The learning and decision-making engine. It contains the AI (a Q-learning agent) that determines the optimal power and fan speed for any given situation.
HVAC_Learning_Orchestrator ("The Conductor"): The strategic training engine. It runs an automated, short-term "Commissioning and Self-Tuning" (CST) routine to rapidly teach the Brain, seeding it with foundational knowledge.
<!-- You can create a simple diagram for this -->
This modular design ensures a clear separation of concerns, making the system robust, maintainable, and highly extensible.
2. Module Deep Dive
Role: To manage the physical state of the HVAC system based on simple, absolute rules or direct commands.
Key Responsibilities (Normal Operation):
Opens/closes room flaps based on temperature, window status, and local AC modes.
Generates "Demand" signals for each room.
Provides system-wide overrides (e.g., disable cooling if heating is active).
API for the Orchestrator: In its new "Override Mode", it becomes a simple command executor.
SetOverrideMode(bool $isOverride): Enables/disables the override mode.
CommandSystem(bool $acOn, bool $fanOn): Directly controls the main AC and Fan relays.
CommandFlaps(string $roomStatesJson): Directly sets the state of multiple room flaps.
GetRoomConfigurations(): string: Exposes its list of configured rooms and linked variables.
Role: To learn and decide the most efficient Power:Fan action for any given cooling demand.
Key Responsibilities (Normal Operation):
Monitors the "Demand" signals from the Zoning Manager.
Continuously assesses the system state (d|c|t|r).
Uses its Q-Table to choose the optimal action.
Learns from the outcome of its actions by updating the Q-Table based on a calculated reward.
API for the Orchestrator: In its new "Orchestrated Mode", its internal timer is disabled, and it acts as a "brain in a jar".
SetMode(string $mode): Switches between cooperative and orchestrated modes.
ForceActionAndLearn(string $forcedAction): string: The core API. It executes a full learning cycle using a provided action and returns the outcome.
ResetLearning(): Clears the Q-Table.
GetActionPairs(): string: Exposes its list of possible Power:Fan actions.
Role: To execute a high-speed, automated training script to build the Brain's initial knowledge base.
Key Responsibilities:
Manages a timer-driven state machine to step through a user-configurable CalibrationPlan.
Dynamically generates a proposed plan by discovering rooms and actions from the other modules.
Systematically permutates Room Count, Demand Level, and Action to create a wide variety of test conditions.
Takes control of the Body and Brain during calibration and releases them upon completion.
3. Setup and Usage Instructions
Follow these steps for a successful installation and commissioning.
Step 1: Install All Three Modules
Place all three module folders (Zoning_and_Demand_Manager, adaptive_HVAC_control, HVAC_Learning_Orchestrator) in your IP-Symcon modules directory and update the module control.
Step 2: Configure the "Body" (Zoning_and_Demand_Manager)
Create an instance of this module.
Configure all your rooms in the Controlled Rooms list. This is the single source of truth for room configuration. Link all temperature sensors, target variables, flaps, and demand variables.
Set the Operating Mode to Cooperative (Adaptive) Mode.
Step 3: Configure the "Brain" (adaptive_HVAC_control)
Create an instance of this module.
Link the core variables (AC Active Link, PowerOutputLink, FanOutputLink, CoilTempLink, MinCoilTempLink).
In the Monitored Rooms list, link the corresponding Temp Sensor, Target Temp, and Demand Variable for each room you configured in the Zoning Manager.
Set the OperatingMode to Cooperative.
Configure your desired CustomPowerLevels and CustomFanSpeeds.
Step 4: Configure the "Conductor" (HVAC_Learning_Orchestrator)
Create an instance of this module.
In the configuration form, link to the instances of the Zoning Manager and Adaptive Control modules you just created.
Click Apply.
Re-open the configuration form. The module will now have discovered your rooms and available actions and will have dynamically generated a proposed Calibration Plan for you.
Step 5: Run the Automated Calibration
Review the proposed CalibrationPlan. The default plan is robust, but you can adjust room names in the flapConfig or tweak the action patterns if desired.
Click Apply to save any changes to the plan.
Click the "Start Calibration" button.
The module's status will change to "Calibration is Running."
The process will take approximately 1.5 - 2 hours. You can monitor the detailed progress in the Symcon log file (Log Level: DEBUG).
The Orchestrator will automatically stop when the plan is complete, its status will change to "Calibration is Done," and it will return the other modules to normal cooperative operation.
Step 6: Enjoy Optimized Performance
Your system is now operating with a well-seeded, intelligent Q-Table. The adaptive_HVAC_control module will now make highly efficient decisions and will continue to fine-tune its knowledge during normal operation.
4. Advanced: The Calibration Plan Explained
The Orchestrator's CalibrationPlan is the script for its automated routine. Each row ("Stage") is a specific experiment.
Stage Name: A descriptive name.
Flap Config: Controls which rooms are active, thereby controlling the Room Count (r) state variable.
Target Temp Offset (°C): Controls the artificial temperature difference, thereby controlling the Demand Level (d) state variable.
Action Pattern: The sequence of Power:Fan Actions to test under the conditions created by the flapConfig and targetOffset.
