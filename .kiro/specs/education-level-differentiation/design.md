# Design Document: Education Level Differentiation

## Overview

The Education Level Differentiation feature enables comprehensive level-based filtering and matching throughout the EduGrowth platform. Users (tutors and students) select a primary education level ("basic" or "secondary") during registration, and this drives course visibility, enrollment eligibility, tutor-student pairing, and personalized recommendations. The system enforces strict level matching to ensure content appropriateness and optimal learning outcomes.

### Key Design Principles

1. **Level as Primary Dimension**: Education level is a first-class, immutable characteristic that governs access and visibility across the platform
2. **Fail-Safe Defaults**: Operations requiring level matching reject with clear errors rather than allowing undefined behavior
3. **Audit Trail**: Critical operations (rejections, migrations) are logged for compliance
4. **Graceful Degradation**: Existing null-level records are handled without errors but excluded from level-filtered results

---

## Architecture

### System Components

```
┌─────────────────────────────────────────────────┐
│              User Registration                  │
│  (Profile Setup includes education_level)      │
└──────────────────┬──────────────────────────────┘
                   │
        ┌──────────┴──────────┐
        │                     │
    ┌───▼────────┐    ┌───────▼────┐
    │   Student  │    │   Tutor    │
    │ (basic/sec)│    │(basic/sec) │
    └───┬────────┘    └───────┬────┘
        │                     │
        │                     │
   ┌────▼─────────────────────▼────┐
   │   Course Visibility           │
   │  (Filter by education_level)  │
   └────┬─────────────────────┬────┘
        │                     │
    ┌───▼────────┐    ┌───────▼────┐
    │ Enrollment │    │Tutor-Course│
    │ Validation │    │  Binding   │
    └───┬────────┘    └───────┬────┘
        │                     │
    ┌───▼─────────────────────▼────┐
    │   Recommendations             │
    │ (Level-matched course ranking)│
    └──────────────────────────────┘
```

### Data Flow

1. **User Registration**: User selects education level during signup
2. **Course Creation**: Tutor creates course (education_level auto-set to tutor's level)
3. **Course Discovery**: Students query courses (automatically filtered by their level)
4. **Enrollment Request**: Student attempts to enroll → system validates level match → allows or rejects
5. **Tutor Discovery**: Student searches for tutors → system filters by matching level
6. **Recommendations**: System recommends courses matching student's level

---

## Components and Interfaces

### 1. User Service Layer

**File**: `app/Http/Controllers/Api/AuthController.php` (registration)

**Responsibilities**:
- Accept education_level during user registration
- Validate education_level is "basic" or "secondary"
- Store education_level in User model
- Return education_level in user profile endpoints

**Key Methods**:
- `register()` - Accept and validate education_level
- `getProfile()` - Include education_level in response

**Validation Rules**:
- `education_level`: required|in:basic,secondary

---

### 2. Course Service Layer

**Files**:
- `app/Http/Controllers/Api/CourseController.php` (main course endpoints)
- `app/Models/Course.php` (model)

**Responsibilities**:
- Enforce education_level on course creation (matches tutor's level)
- Expose education_level in course endpoints
- Filter courses by student's education_level on index/search
- Prevent tutors from creating courses at different levels

**Key Methods**:

```php
// In CourseController
- store()           // Validate tutor level, auto-set course.education_level
- index()           // Filter by student.education_level
- update()          // Prevent level changes
- getRecommendations() // Already implemented with level filtering
- showPublic()      // Include education_level in public course view
```

**Validation Rules**:
- Course creation: education_level must match tutor's education_level
- Course update: education_level cannot be changed
- Course retrieval: Filter by authenticated user's education_level if student

---

### 3. Enrollment Service Layer

**Files**:
- `app/Http/Controllers/Api/EnrollmentController.php`
- `app/Models/Enrollment.php`

**Responsibilities**:
- Validate education_level match before enrollment
- Return 409 Conflict if levels don't match
- Log enrollment rejections for audit
- Ensure atomicity (reject before persistence)

**Key Methods**:

```php
// In EnrollmentController
- enroll()
  1. Validate student.education_level == course.education_level
  2. If mismatch: reject with 409 Conflict
  3. If match: create enrollment record
  4. Log rejection events
```

**Error Responses**:
- 409 Conflict: `{ "message": "Course is not available for your education level" }`
- 403 Forbidden: Tutor cannot enroll in own course

---

### 4. Tutor Discovery Service Layer

**Files**:
- `app/Http/Controllers/Api/TutorController.php`
- `app/Models/User.php`

**Responsibilities**:
- Filter tutors by education_level in discovery endpoints
- Return education_level in tutor profiles
- Prevent access to tutors at different levels

**Key Methods**:

```php
// In TutorController
- tutors()          // Filter by authenticated student's education_level
- getProfile()      // Include education_level
```

---

### 5. Recommendation Engine

**Files**:
- `app/Http/Controllers/Api/CourseController.php` (getRecommendations)

**Responsibilities**:
- Filter recommendations by student's education_level
- Exclude courses at different levels
- Return empty list for students with null education_level
- Rank within level by other criteria

**Current Implementation** (Already done):
```php
$recommendedCourses = Course::where('education_level', $user->education_level)
    ->whereNotIn('id', $enrolledCourseIds)
    ->where('tutor_id', '!=', $user->id)
    ->latest()
    ->limit(10)
    ->get();
```

---

## Data Models

### User Table

**Current State**: Migration `2024_01_15_add_education_level_to_users_table.php` adds:
```php
$table->enum('education_level', ['basic', 'secondary'])->nullable();
```

**Properties**:
- `id` (Primary Key)
- `name` (String)
- `email` (String, Unique)
- `password` (String, Hashed)
- `role` (Enum: student, tutor, admin)
- `education_level` (Enum: basic, secondary, Nullable for backward compatibility)
- `is_approved` (Boolean)
- Profile fields: `first_name`, `last_name`, `phone_number`, `location`, `biography`, `school_name`, `core_subjects`

**Validation**:
- `education_level` must be one of: ['basic', 'secondary']
- `education_level` cannot be null for operations requiring level checking

---

### Course Table

**Current State**: Migration `2024_01_16_add_education_level_to_courses_table.php` adds:
```php
$table->enum('education_level', ['basic', 'secondary'])->nullable();
```

**Properties**:
- `id` (Primary Key)
- `tutor_id` (Foreign Key → users.id)
- `title` (String)
- `description` (Text)
- `type` (Enum: free, paid)
- `price` (Decimal)
- `education_level` (Enum: basic, secondary, Nullable)
- `created_at`, `updated_at` (Timestamps)

**Relationships**:
- `tutor()` - BelongsTo User
- `lessons()` - HasMany Lesson
- `enrollments()` - HasMany Enrollment

**Invariant**: `course.education_level == course.tutor.education_level` (enforced on creation/update)

---

### Enrollment Table

**Current State**: Unchanged - already has `user_id` and `course_id` foreign keys

**Properties**:
- `id` (Primary Key)
- `user_id` (Foreign Key → users.id)
- `course_id` (Foreign Key → courses.id)
- `enrolled_at` (Timestamp)
- `created_at`, `updated_at` (Timestamps)

**Relationships**:
- `user()` - BelongsTo User
- `course()` - BelongsTo Course

**Validation Invariant**: User and Course must have matching non-null education_level values

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system—essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Education Level Consistency

**Statement**: For all users, if an education_level is set, it SHALL be either "basic" or "secondary", never any other value.

**Validates: Requirements 1.1, 1.5**

**How to Test**: Generate random user creation operations with various education_level inputs. Verify that:
- Valid levels ("basic", "secondary") are persisted unchanged
- Invalid levels are rejected with validation errors
- Persisted users always have education_level in the valid set

---

### Property 2: Course-Student Level Matching

**Statement**: A student can enroll in a course if and only if both the student and course have matching non-null education_level values.

**Validates: Requirements 5.1, 5.4**

**How to Test**: For all combinations of student and course education levels:
- Student basic + Course basic = Enrollment succeeds
- Student secondary + Course secondary = Enrollment succeeds
- Student basic + Course secondary = Enrollment rejected (409)
- Student secondary + Course basic = Enrollment rejected (409)
- Student or Course null level = Enrollment rejected
- Verify no enrollment records created for rejected cases

---

### Property 3: Course Visibility Invariant

**Statement**: When a student with education_level "X" queries the course list, the returned courses SHALL only contain courses with education_level "X", and SHALL exclude courses with any other education_level or null.

**Validates: Requirements 3.1, 3.2, 3.3**

**How to Test**: For all combinations of student levels and course levels:
- Create random courses at both basic and secondary levels
- Query as student with specific level
- Verify returned courses contain ONLY courses matching that level
- Verify courses at other levels are excluded
- Verify courses with null level are excluded

---

### Property 4: Tutor-Course Level Binding

**Statement**: For all courses, the course's education_level SHALL match the tutor's education_level who created or owns the course.

**Validates: Requirements 4.4, 7.1, 7.3**

**How to Test**: For all tutor-course pairs:
- Create tutors at basic and secondary levels
- Create courses for each tutor
- Verify course.education_level == course.tutor.education_level
- Attempt to update course level to differ from tutor level
- Verify update is rejected with 422 Unprocessable Entity

---

### Property 5: Recommendation Filter Correctness

**Statement**: For all recommendation results returned to a student, 100% of recommended courses SHALL have an education_level matching the requesting student's education_level.

**Validates: Requirements 6.1, 6.2, 6.5**

**How to Test**: For all student education levels:
- Create mixed courses at both levels
- Request recommendations as student with specific level
- Verify every returned course has education_level matching request
- Verify courses at other levels are excluded from recommendations
- If no courses available at student's level, verify empty list (not default to other levels)

---

### Property 6: Enrollment Rejection Consistency

**Statement**: If enrollment creation is rejected due to education level mismatch, the rejection SHALL occur before any enrollment record is persisted to the database.

**Validates: Requirements 5.3, 6.6**

**How to Test**: For all mismatched student-course level pairs:
- Query enrollment count before attempt
- Attempt enrollment with mismatched levels
- Verify operation returns 409 Conflict
- Query enrollment count after attempt
- Verify count is unchanged (atomicity)
- Verify no new enrollment record exists in database

---

### Property 7: Idempotence of Level Queries

**Statement**: Querying the course list multiple times with the same education_level SHALL return the same set of courses (in terms of course IDs, regardless of order) with the same education_level values.

**Validates: Requirements 3.3, 9.2**

**How to Test**: For fixed student level:
- Create stable course set at that level
- Query course list 3+ times
- Extract course IDs from each response
- Verify all queries return identical course sets (order-independent)
- Verify each course has correct education_level in every response

---

### Property 8: Round-Trip Education Level

**Statement**: When a user's education_level is set to a value, retrieved, and re-persisted, the value SHALL remain unchanged through the round trip.

**Validates: Requirements 1.3, 9.1**

**How to Test**: For all valid education_level values (basic, secondary):
- Create user with specific level
- Retrieve user via API
- Update user with same level
- Retrieve again
- Verify level is unchanged across all operations
- Verify serialization/deserialization maintains value fidelity

---

### Property 9: Migration Preserves Data Integrity

**Statement**: Existing users and courses without an education_level SHALL not raise errors when queried, but SHALL be excluded from level-filtered results or handled gracefully per business rules.

**Validates: Requirements 2.2, 10.2, 10.4**

**How to Test** (Integration Test): 
- Create users/courses without education_level (or with null)
- Run migration
- Query without filters: Verify no errors
- Query with filters: Verify null-level records excluded
- Query users list: Verify no errors for null education_level
- Attempt to create course without setting education_level: Verify rejection with guidance

---

### Property 10: Metamorphic Relation - Level Filter Commutativity

**Statement**: Filtering courses first by education_level and then by other criteria (e.g., tutor_id) SHALL produce the same result as filtering by other criteria first and then by education_level.

**Validates: Requirements 3.1, 9.2**

**How to Test**: For all combinations of filters:
- Create courses at various levels and tutors
- Filter by level first, then by tutor: Get result set A
- Filter by tutor first, then by level: Get result set B
- Verify result set A == result set B (same course IDs)
- Repeat for multiple tutor/level combinations

---

## Error Handling

### Validation Errors

**Invalid Education Level** (400/422):
```json
{
  "errors": {
    "education_level": ["The education_level must be one of: basic, secondary"]
  }
}
```

**Null Education Level During Operations** (422):
```json
{
  "errors": {
    "education_level": ["Education level is required to perform this action"]
  }
}
```

---

### Business Logic Errors

**Enrollment Mismatch** (409):
```json
{
  "message": "Course is not available for your education level"
}
```

**Tutor Level Mismatch on Course Creation** (422):
```json
{
  "message": "Course education level must match your education level"
}
```

**Tutor Missing Education Level** (422):
```json
{
  "message": "Please set your education level before creating courses"
}
```

**Tutor Cannot Enroll in Own Course** (400):
```json
{
  "message": "You cannot enroll in your own course"
}
```

**Access Denied to Different-Level Tutor** (403):
```json
{
  "message": "You cannot access tutors at different education levels"
}
```

---

### Audit Logging

**Events to Log**:
1. Enrollment rejection (education level mismatch)
   - Fields: user_id, course_id, student_level, course_level, timestamp
2. Course creation rejection (tutor level mismatch)
   - Fields: tutor_id, requested_level, tutor_level, timestamp
3. Course operation on null education level
   - Fields: tutor_id, operation, timestamp

**Log Level**: WARNING or INFO (depending on occurrence frequency)

---

## Testing Strategy

### Unit Tests (Example-Based)

**Test Coverage**:
1. **User Registration**: Valid/invalid education_level inputs
2. **Course Creation**: Tutor level matching, null level rejection
3. **Enrollment Validation**: Level matching logic, error cases
4. **Tutor Discovery**: Filtering by level, access control

**Examples to Test**:
- Student basic enrolls in course basic → Success
- Student secondary enrolls in course basic → 409 Conflict
- Tutor creates course → Auto-set to tutor's level
- Tutor attempts to change course level → Rejected
- Query courses as student → Only matching level returned
- Query tutors as student → Only matching level returned

---

### Property-Based Tests

**Run Minimum 100 Iterations Per Property**

**Properties to Test** (mapped to Correctness Properties section above):

| Property # | Test Name | Input Space | Key Invariant |
|----------|-----------|-------------|---------------|
| 1 | Level Validity | Random user attributes | All levels in {basic, secondary} |
| 2 | Enrollment Matching | Random student/course combinations | Enrollment succeeds ⟺ levels match |
| 3 | Course Visibility | Random student levels, course levels | Returned courses match student level |
| 4 | Tutor-Course Binding | Random tutor-course creation | course.level == tutor.level |
| 5 | Recommendation Filter | Random student levels | All recommendations match level |
| 6 | Atomicity | Random mismatched enrollments | No record persisted on rejection |
| 7 | Query Idempotence | Fixed student level, multiple queries | Same courses returned each query |
| 8 | Round-Trip | Random valid levels | Level survives create→retrieve→update→retrieve |
| 9 | Null Handling | Pre-migration data | No errors, null records excluded |
| 10 | Filter Commutativity | Random filter combinations | Result order-independent |

---

### Integration Tests

**Test Coverage**:
1. User registration flow with education_level
2. Full course discovery → enrollment flow
3. Migration from pre-education_level data
4. Tutor profile → course creation flow
5. Recommendation generation with mixed courses

**Examples**:
- Complete student journey: register → browse courses → enroll
- Complete tutor journey: register → set level → create course → students discover
- Migration: Pre-migration data remains queryable, filtered correctly after migration

---

## Implementation Notes

### Already Implemented

✅ User model has `education_level` in fillable
✅ Course model has `education_level` in fillable
✅ Migrations created for both tables
✅ Course creation auto-sets `education_level` to tutor's level
✅ Enrollment validation checks level match (returns 403)
✅ Course index filters by student's education_level
✅ Recommendations filter by education_level
✅ Course endpoints expose education_level

### Remaining Work

- [ ] Update enrollment error code from 403 to 409 (per Requirement 5.2)
- [ ] Add logging for enrollment rejections
- [ ] Validate tutor level on update (prevent level change)
- [ ] Add education_level to tutor discovery (GET /api/tutors)
- [ ] Add education_level validation to registration/profile endpoints
- [ ] Write property-based tests for all 10 properties
- [ ] Write integration tests for end-to-end flows
- [ ] Handle null education_level edge cases gracefully

---

## API Endpoint Summary

| Endpoint | Method | Auth | Education Level Handling |
|----------|--------|------|--------------------------|
| /api/register | POST | No | Accept education_level, validate |
| /api/users/{id} | GET | Yes | Return education_level |
| /api/courses | GET | Yes | Filter by user.education_level if student |
| /api/courses/{id} | GET | No | Return education_level |
| /api/tutor/courses | POST | Yes (tutor) | Auto-set to tutor.education_level |
| /api/tutor/courses/{id} | PUT | Yes (tutor) | Prevent level changes |
| /api/enrollments | POST | Yes (student) | Validate level match, reject 409 if mismatch |
| /api/tutors | GET | Yes (student) | Filter by student.education_level |
| /api/tutors/{id} | GET | Yes | Check level access, return education_level |
| /api/courses/recommendations | GET | Yes | Filter by user.education_level |

