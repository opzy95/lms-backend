# Requirements Document

## Education Level Differentiation Requirements

### Introduction

The EduGrowth backend currently allows users to select between basic or secondary education levels during registration, but this information is not persisted or utilized for any business logic. This feature adds comprehensive education level support throughout the platform, enabling proper course-to-user matching, tutor-student pairing at appropriate levels, and personalized course recommendations. The system will enforce that students and tutors at different education levels cannot interact directly, ensuring content appropriateness and learning outcomes.

## Glossary

- **Education_Level**: A classification attribute representing a user's academic level. Valid values are "basic" or "secondary".
- **Basic_Education_Level**: Encompasses users at primary or junior high school level.
- **Secondary_Education_Level**: Encompasses users at high school level.
- **User_Profile**: The collection of user attributes including name, email, role, approval status, and education level.
- **Course_Visibility**: The set of courses available to a user based on matching education level criteria.
- **Tutor_Student_Pairing**: A relationship where a student can only be taught by a tutor at the same or higher education level.
- **Course_Enrollment**: The action of a student registering for a course taught by a tutor.
- **Level_Based_Recommendation**: A course suggestion algorithm that prioritizes courses matching the user's education level.
- **Education_Level_Mismatch**: A condition where a student attempts to enroll in a course offered by a tutor at a different education level.
- **Platform**: The EduGrowth backend API and its associated database.

## Requirements

### Requirement 1: Store Education Level in User Profile

**User Story:** As a platform administrator, I want education levels to be persisted for every user, so that the system can enforce level-based business rules and recommendations.

#### Acceptance Criteria

1. WHEN a user record is created or updated, THE Platform SHALL store the user's education_level as either "basic" or "secondary".
2. THE User model SHALL include education_level as a fillable and cast-able attribute.
3. WHEN retrieving user data via the API, THE Platform SHALL include education_level in the response payload.
4. WHERE a user's education_level is null or missing during update, THE Platform SHALL reject the operation and return a validation error.
5. IF a user attempts to set education_level to an invalid value, THEN THE Platform SHALL return a validation error indicating valid values are "basic" or "secondary".

### Requirement 2: Add Education Level Column to Users Table

**User Story:** As a database administrator, I want the users table schema to include an education_level column, so that user education levels are stored durably.

#### Acceptance Criteria

1. WHEN users table is migrated, THE Platform SHALL add a nullable string column education_level with default value null.
2. WHEN existing users are queried, THE Platform SHALL gracefully handle records with null education_level without errors.
3. THE education_level column SHALL support "basic" and "secondary" as valid string values.
4. WHEN the migration is rolled back, THE Platform SHALL remove the education_level column and maintain data integrity.

### Requirement 3: Filter Courses by Education Level

**User Story:** As a student, I want to see only courses matching my education level, so that I can find relevant content.

#### Acceptance Criteria

1. WHEN a student requests the list of available courses, THE CourseService SHALL filter courses to include only those where the course's education_level matches the student's education_level.
2. WHEN a student with "basic" education level requests courses, THE CourseService SHALL return only courses tagged as "basic".
3. WHEN a student with "secondary" education level requests courses, THE CourseService SHALL return only courses tagged as "secondary".
4. WHEN a student with null education_level requests courses, THE CourseService SHALL return an empty list and log this condition for monitoring.
5. IF a course's education_level is null or invalid, THEN THE CourseService SHALL exclude it from all filtered course lists.

### Requirement 4: Add Education Level Support to Courses

**User Story:** As a tutor, I want to specify the education level(s) my courses are designed for, so that students at the appropriate level can find them.

#### Acceptance Criteria

1. WHEN a tutor creates a course, THE Tutor SHALL be required to specify the course's education_level as either "basic" or "secondary".
2. WHEN a tutor updates an existing course, THE Platform SHALL allow modification of the course's education_level.
3. THE Course model SHALL include education_level as a fillable and searchable attribute.
4. WHEN retrieving course data via the API, THE Platform SHALL include the course's education_level in the response payload.
5. IF a tutor attempts to create or update a course without specifying education_level, THEN THE Platform SHALL return a validation error.

### Requirement 5: Enforce Education Level Matching for Enrollments

**User Story:** As the system, I want to prevent students from enrolling in courses taught by tutors at different education levels, so that only appropriate student-tutor pairings occur.

#### Acceptance Criteria

1. WHEN a student attempts to enroll in a course, THE EnrollmentService SHALL verify that the student's education_level matches the course's education_level.
2. IF the student's education_level does not match the course's education_level, THEN THE Platform SHALL reject the enrollment and return a 409 Conflict error with message "Course is not available for your education level".
3. WHEN an enrollment is validated, THE Platform SHALL perform the education level check before creating the enrollment record.
4. IF either the student or course lacks an education_level, THEN THE Platform SHALL reject the enrollment to prevent undefined behavior.
5. WHEN an enrollment validation fails, THE Platform SHALL log the mismatch event including user_id, course_id, student_level, and course_level for audit purposes.

### Requirement 6: Implement Level-Based Course Recommendations

**User Story:** As a student, I want to receive course recommendations, so that I can discover relevant content suited to my education level.

#### Acceptance Criteria

1. WHEN a student requests course recommendations, THE RecommendationEngine SHALL prioritize courses matching the student's education_level.
2. THE RecommendationEngine SHALL exclude courses where education_level does not match the requesting student's education_level.
3. WHEN generating recommendations, THE RecommendationEngine SHALL apply additional ranking criteria (such as rating, enrollment count, or creation date) within the education level filter.
4. WHERE a student has a null education_level, THE RecommendationEngine SHALL return an empty recommendation list.
5. IF no courses are available for the student's education level, THEN THE RecommendationEngine SHALL return an empty list and not recommend courses from other education levels.

### Requirement 7: Prevent Tutors from Teaching Across Education Levels

**User Story:** As a tutor, I want to create and manage courses for a specific education level, so that my expertise aligns with the content I teach.

#### Acceptance Criteria

1. WHEN a tutor creates a course, THE Platform SHALL set the course's education_level to match the tutor's own education_level.
2. IF a tutor attempts to create a course with an education_level different from their own, THEN THE Platform SHALL reject the operation and return a 422 Unprocessable Entity error with message "Course education level must match your education level".
3. WHEN a tutor updates an existing course, THE Platform SHALL prevent changing the course's education_level to differ from the tutor's education_level.
4. IF a tutor's education_level is null, THEN THE Platform SHALL reject course creation with an error message instructing the tutor to set their education level first.
5. WHEN rejecting course operations due to education level mismatch, THE Platform SHALL log the rejected operation for audit purposes.

### Requirement 8: Enable Tutors and Students at Same Level to Connect

**User Story:** As a tutor and student at the same education level, I want the system to facilitate my connection, so that appropriate learning relationships can form.

#### Acceptance Criteria

1. WHEN a student searches for available tutors, THE TutorService SHALL filter tutors to include only those with matching education_level.
2. WHEN a student with "basic" education level searches for tutors, THE TutorService SHALL return only tutors with "basic" education_level.
3. WHEN a student with "secondary" education level searches for tutors, THE TutorService SHALL return only tutors with "secondary" education_level.
4. WHEN a tutor's profile is viewed by a student with matching education_level, THE Platform SHALL display full tutor details including qualifications and course catalog.
5. IF a student attempts to view a tutor profile at a different education_level, THEN THE Platform SHALL return a 403 Forbidden error or exclude the tutor from discovery results.

### Requirement 9: Expose Education Level in API Endpoints

**User Story:** As a frontend developer, I want education level information in API responses, so that I can build UI filtering and matching logic.

#### Acceptance Criteria

1. WHEN the GET /api/users/:id endpoint is called, THE Platform SHALL include education_level in the JSON response.
2. WHEN the GET /api/courses endpoint is called, THE Platform SHALL include education_level in each course object.
3. WHEN the GET /api/tutors endpoint is called, THE Platform SHALL include education_level for each tutor user.
4. WHEN the POST /api/enrollments endpoint is called with valid education level matching, THE Platform SHALL return the created enrollment with HTTP 201 Created.
5. WHEN the POST /api/enrollments endpoint is called with education level mismatch, THE Platform SHALL return HTTP 409 Conflict with error details.

### Requirement 10: Handle Migration Path for Existing Users and Courses

**User Story:** As a system maintainer, I want existing users and courses to be handled gracefully during the migration, so that the system remains stable.

#### Acceptance Criteria

1. WHEN the migration runs on existing data, THE Platform SHALL add the education_level column to users and courses tables with null default.
2. IF existing users have null education_level, THEN platform API calls that require education_level checking SHALL either reject the operation or prompt the user to set their education level.
3. WHEN an existing tutor attempts to create a course without setting their education_level, THE Platform SHALL reject the operation with clear guidance.
4. WHERE a course was created before the migration and lacks an education_level, THE Platform SHALL exclude it from filtered course lists until an education_level is assigned.
5. WHEN existing data is queried, THE Platform SHALL not return errors due to null education_level but instead handle gracefully per Requirements 4-5.

---

## Correctness Properties

### Property 1: Education Level Consistency

**Statement:** For all users, if an education_level is set, it SHALL be either "basic" or "secondary", never any other value.

**Testable As:** Property-based test with invariant checking. Generate random user creation/update operations, verify all persisted users have valid education_level enum values.

### Property 2: Course-Student Level Matching

**Statement:** A student can enroll in a course if and only if both the student and course have matching non-null education_level values.

**Testable As:** Property-based test with rule verification. For all combinations of student education levels and course education levels, verify enrollment succeeds only when levels match and both are non-null.

### Property 3: Course Visibility Invariant

**Statement:** When a student with education_level "X" queries the course list, the returned courses SHALL only contain courses with education_level "X", and SHALL exclude courses with any other education_level or null.

**Testable As:** Property-based test with filtering verification. For all combinations of student levels and course levels, verify the filter returns exactly the matching subset.

### Property 4: Tutor-Course Level Binding

**Statement:** For all courses, the course's education_level SHALL match the tutor's education_level who created or owns the course.

**Testable As:** Property-based test with relationship invariant. For all tutor-course pairs, verify course.education_level == course.tutor.education_level.

### Property 5: Recommendation Filter Correctness

**Statement:** For all recommendation results returned to a student, 100% of recommended courses SHALL have an education_level matching the requesting student's education_level.

**Testable As:** Property-based test with result validation. For all recommendation requests with varying student levels, verify every returned course matches the requester's level.

### Property 6: Enrollment Rejection Consistency

**Statement:** If enrollment creation is rejected due to education level mismatch, the rejection SHALL occur before any enrollment record is persisted to the database.

**Testable As:** Property-based test with atomicity verification. For all attempted enrollments with mismatched levels, verify enrollment count remains unchanged and rejection is returned to the client.

### Property 7: Idempotence of Level Queries

**Statement:** Querying the course list multiple times with the same education_level SHALL return the same set of courses (in terms of course IDs, regardless of order) with the same education_level values.

**Testable As:** Property-based test with idempotence verification. For fixed student level, make multiple course list queries and verify identical course sets are returned.

### Property 8: Round-Trip Education Level

**Statement:** When a user's education_level is set to a value, retrieved, and re-persisted, the value SHALL remain unchanged through the round trip.

**Testable As:** Property-based test with serialization round-trip. For all valid education_level values, create user, retrieve, update, retrieve again, verify value consistency.

### Property 9: Migration Preserves Data Integrity

**Statement:** Existing users and courses without an education_level SHALL not raise errors when queried, but SHALL be excluded from level-filtered results or handled gracefully per business rules.

**Testable As:** Integration test. Create users/courses, migrate, verify no errors on basic queries, verify filtered queries exclude null-level records.

### Property 10: Metamorphic Relation - Level Filter Commutativity

**Statement:** Filtering courses first by education_level and then by other criteria (e.g., tutor_id) SHALL produce the same result as filtering by other criteria first and then by education_level.

**Testable As:** Property-based test with filtering order independence. For all combinations of filters, verify filtering order doesn't affect final result set.

