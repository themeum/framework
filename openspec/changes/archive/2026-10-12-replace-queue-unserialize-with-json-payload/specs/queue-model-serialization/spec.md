## MODIFIED Requirements

### Requirement: Opt-in model serialization
Model serialization SHALL apply only to jobs that opt in through the model-serialization trait. A
job that does not opt in MUST store models as the allowed object types the payload encoding
supports, frozen as they were at dispatch. Opting in MUST NOT change how any other property value
is stored.

#### Scenario: Job without the trait
- **WHEN** a job that does not use the trait holds a saved model and is dispatched and processed
- **THEN** the processed job's model has the attribute values it had at dispatch, even if the row changed in between

#### Scenario: Job with the trait
- **WHEN** a job that uses the trait holds a saved model, the model's row is updated after dispatch, and the job is processed
- **THEN** the processed job's model has the updated attribute values

#### Scenario: Other properties are untouched
- **WHEN** a job that uses the trait also holds an integer, a string, an array and an instance of a class the job declares as allowed
- **THEN** those values are restored exactly as they were at dispatch

#### Scenario: Identifiers survive the payload encoding
- **WHEN** a job that uses the trait holds a saved model and a model collection and is dispatched
- **THEN** the stored payload contains their class and key information in the JSON payload, and processing the job re-fetches both from the database
