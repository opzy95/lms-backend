# Implementation Plan: Education Level Differentiation

## Overview

This implementation plan breaks down the education level differentiation feature into discrete, testable tasks. The feature enables level-based filtering, course visibility, enrollment matching, and personalized recommendations. Most infrastructure has been built (migrations, models, basic endpoints); this plan focuses on completing validation logic, adding missing endpoints, and implementing comprehensive testing.

---

## Tasks

- [ ] 1. Validate and complete user education level support
  - [ ] 1.1 Add education_level validation to registration endpoint
    - Add validation rule: `education_level` required|in:basic,secondary
    - Test with valid and invalid levels
    - Return 422 with validation errors for invalid levels
    - _Requirements: 1.1, 1.5_
  
  - [ ]* 1.2 Write property test for education level validity
    - **Property 1: Education Level Consistency**
    - **Validates: Requirements 1.1, 1.5**
    - Generate random user data with various education_level inputs
    - Verify only basic/secondary persist
    - Verify invalid levels rejected

  - [ ] 1.3 Add education_level to user profile response endpoints
    - Include education_level in GET /api/users/{id}
    - Include education_level in profile update responses
    - Test that education_level is returned consistently
    - _Requirements: 1.3, 9.1_

  - [ ] 1.4 Add education_level validation on user profile updates
    - Prevent changing education_level to invalid values
    - Allow null→valid transitions for migration support
    - Return 422 with clear error messages
    - _Requirements: 1.5_

- [ ] 2. Complete course education level enforcement
  - [ ] 2.1 Verify course creation auto-sets education_level to tutor's level
    - Confirm CourseController.store() sets education_level from tutor
    - Test that created courses have matching tutor level
    - Test with tutors at both basic and secondary levels
    - _Requirements: 4.1, 7.1_

  - [ ]* 2.2 Write property test for tutor-course level binding
    - **Property 4: Tutor-Course Level Binding**
    - **Validates: Requirements 4.4, 7.1, 7.3**
    - Create tutors at both levels, verify courses inherit level
    - Attempt to set course level differently from tutor
    - Verify rejection with proper error

  - [ ] 2.3 Validate tutor level on course updates
    - Prevent changing course.education_level on PUT
    - Return 422 if update attempts to change level
    - Allow updates to other fields (title, description, price)
    - Log rejected level-change attempts
    - _Requirements: 7.3_

  - [ ] 2.4 Add education_level to course API responses
    - Ensure CourseController.index() includes education_level
    - Ensure CourseController.show() includes education_level
    - Ensure CourseController.showPublic() includes education_level
    - Verify all course list endpoints expose education_level
    - _Requirements: 4.2, 9.2_

  - [ ] 2.5 Add education_level validation on tutor course creation
    - Check tutor has non-null education_level
    - Reject course creation if tutor.education_level is null
    - Return 422 with message: "Please set your education level before creating courses"
    - _Requirements: 7.4_

- [ ] 3. Complete course filtering by education level
  - [ ] 3.1 Verify course index filters by student's education level
    - Test CourseController.index() with student role
    - Verify only courses matching student.education_level returned
    - Verify courses at other levels excluded
    - Verify null-level courses excluded
    - _Requirements: 3.1, 3.2, 3.3_

  - [ ]* 3.2 Write property test for course visibility invariant
    - **Property 3: Course Visibility Invariant**
    - **Validates: Requirements 3.1, 3.2, 3.3**
    - For all student/course level combinations:
      - Create courses at both levels
      - Query as student with specific level
      - Verify returned only match student level
      - Verify other levels excluded
      - Verify null-level excluded

  - [ ] 3.3 Handle null education_level gracefully in course queries
    - Students with null education_level should get empty course list
    - Log this condition for monitoring
    - Return 200 with empty array, not error
    - _Requirements: 3.4, 10.2_

- [ ] 4. Complete enrollment education level validation
  - [ ] 4.1 Update enrollment error code to 409 (per spec)
    - Change EnrollmentController.enroll() error from 403 to 409
    - Update error message: "Course is not available for your education level"
    - Test that mismatched enrollments return 409, not 403
    - _Requirements: 5.2_

  - [ ]* 4.2 Write property test for enrollment matching
    - **Property 2: Course-Student Level Matching**
    - **Validates: Requirements 5.1, 5.4**
    - For all student/course level combinations:
      - Matching levels (basic+basic, secondary+secondary): Succeed
      - Mismatched levels: Reject with 409
      - Null levels: Reject with 409
      - Verify no enrollment records created on rejection

  - [ ] 4.3 Add enrollment rejection logging
    - Log when enrollment rejected due to level mismatch
    - Include: user_id, course_id, student_level, course_level, timestamp
    - Use Log::warning() with structured data
    - Test log output contains all required fields
    - _Requirements: 5.5_

  - [ ]* 4.4 Write property test for enrollment atomicity
    - **Property 6: Enrollment Rejection Consistency**
    - **Validates: Requirements 5.3, 6.6**
    - Query enrollment count before attempt
    - Attempt mismatched enrollment
    - Verify 409 returned
    - Verify count unchanged (atomicity)
    - Verify no record in database

  - [ ] 4.5 Validate that null education_levels are rejected during enrollment
    - Check student.education_level is not null
    - Check course.education_level is not null
    - Reject if either is null
    - Return 422 with clear message
    - _Requirements: 5.4_

- [ ] 5. Implement tutor discovery filtering
  - [ ] 5.1 Add education_level filtering to tutor listing endpoint
    - Create/update GET /api/tutors endpoint
    - Filter tutors by authenticated student's education_level
    - Return only tutors matching student's level
    - Include education_level in each tutor object
    - Return 401 if student not authenticated
    - _Requirements: 8.1, 8.2, 8.3, 9.3_

  - [ ]* 5.2 Write unit tests for tutor discovery filtering
    - Test tutor list with student at basic level
    - Verify only basic-level tutors returned
    - Test tutor list with student at secondary level
    - Verify only secondary-level tutors returned
    - Test admin access returns all tutors

  - [ ] 5.3 Add education_level to tutor profile endpoint
    - Ensure TutorController.getProfile() includes education_level
    - Include education_level in tutor update response
    - Verify all tutor profile endpoints expose education_level
    - _Requirements: 8.4, 9.3_

  - [ ] 5.4 Add access control to tutor profile viewing
    - Students can only view tutor profiles at same education_level
    - Return 403 if student attempts to view different-level tutor
    - Admins can view any tutor profile
    - Test access control at each level
    - _Requirements: 8.5_

- [ ] 6. Verify recommendations engine
  - [ ] 6.1 Confirm recommendations filter by education_level
    - Verify CourseController.getRecommendations() implemented (already done)
    - Test recommendations with student at basic level
    - Verify only basic-level courses in results
    - Test recommendations with student at secondary level
    - Verify only secondary-level courses in results
    - _Requirements: 6.1, 6.2_

  - [ ]* 6.2 Write property test for recommendation filtering
    - **Property 5: Recommendation Filter Correctness**
    - **Validates: Requirements 6.1, 6.2, 6.5**
    - For all student education levels:
      - Create mixed courses (both basic and secondary)
      - Request recommendations as student
      - Verify 100% of returned courses match level
      - Verify other-level courses excluded
      - If no courses at level: verify empty list (not default)

  - [ ] 6.3 Handle null education_level in recommendations
    - Students with null education_level return empty recommendation list
    - Do not default to other levels
    - Log null education_level condition
    - Return 200 with empty array
    - _Requirements: 6.4_

  - [ ]* 6.4 Write property test for idempotent recommendations
    - **Property 7: Idempotence of Level Queries**
    - **Validates: Requirements 3.3, 9.2**
    - Fix student level, query recommendations 3+ times
    - Verify identical course sets returned (order-independent)
    - Verify each course has correct education_level

- [ ] 7. Implement round-trip serialization tests
  - [ ]* 7.1 Write property test for round-trip education level
    - **Property 8: Round-Trip Education Level**
    - **Validates: Requirements 1.3, 9.1**
    - For all valid levels (basic, secondary):
      - Create user with level
      - Retrieve via API
      - Update with same level
      - Retrieve again
      - Verify level unchanged through all operations

- [ ] 8. Implement migration compatibility tests
  - [ ] 8.1 Verify existing data without education_level is handled
    - Test querying users with null education_level (no errors)
    - Test querying courses with null education_level (no errors)
    - Test filtering excludes null-level records gracefully
    - _Requirements: 2.2, 10.2_

  - [ ]* 8.2 Write integration test for migration compatibility
    - **Property 9: Migration Preserves Data Integrity**
    - **Validates: Requirements 2.2, 10.2, 10.4**
    - Create data without education_level
    - Run migrations
    - Query without filters: verify no errors
    - Query with level filters: verify null excluded
    - Attempt course creation without level: verify rejection

  - [ ] 8.3 Test tutor profile creation/update with null level
    - Tutor can set education_level in profile update
    - Tutor cannot create courses until level is set
    - Verify clear error message guiding tutor to set level
    - _Requirements: 10.3_

- [ ] 9. Implement filter commutativity tests
  - [ ]* 9.1 Write property test for filter order independence
    - **Property 10: Metamorphic Relation - Level Filter Commutativity**
    - **Validates: Requirements 3.1, 9.2**
    - For all filter combinations:
      - Filter by level first, then tutor: Result set A
      - Filter by tutor first, then level: Result set B
      - Verify A == B (same course IDs)
      - Repeat for multiple tutor/level combinations

- [ ] 10. Ensure comprehensive API endpoint exposure
  - [ ] 10.1 Verify all user endpoints include education_level
    - GET /api/users/{id}: includes education_level
    - GET /api/auth/me: includes education_level (if exists)
    - POST /api/auth/register: accepts education_level
    - PUT /api/profile: returns updated education_level
    - Test all endpoints return education_level consistently
    - _Requirements: 9.1_

  - [ ] 10.2 Verify all course endpoints include education_level
    - GET /api/courses: includes education_level for each course
    - GET /api/courses/{id}: includes education_level
    - GET /api/tutor/my-courses: includes education_level
    - POST /api/tutor/courses: returns created course with education_level
    - PUT /api/tutor/courses/{id}: returns updated course
    - GET /api/courses/recommendations: all results include education_level
    - _Requirements: 9.2, 9.4_

  - [ ] 10.3 Verify enrollment endpoints handle education_level correctly
    - POST /api/enrollments: returns 409 with error on level mismatch
    - POST /api/enrollments: returns 201 with enrollment on match
    - GET /api/enrollments: includes course.education_level in response
    - _Requirements: 9.4, 9.5_

  - [ ] 10.4 Verify tutor endpoints include education_level
    - GET /api/tutors: all tutors include education_level and are filtered
    - GET /api/tutors/{id}: includes education_level
    - GET /api/tutor/profile: includes education_level
    - PUT /api/tutor/profile: returns education_level
    - _Requirements: 9.3_

- [ ] 11. Write comprehensive integration tests
  - [ ] 11.1 Integration test: Complete student journey
    - Register as student with basic level
    - Query available courses (verify basic-level only)
    - Query tutors (verify basic-level only)
    - Enroll in course (verify success with matching level)
    - Attempt enroll at secondary course (verify 409 rejection)
    - _Requirements: 3.1, 5.1, 8.1, 8.2_

  - [ ] 11.2 Integration test: Complete tutor journey
    - Register as tutor with secondary level
    - Update profile with education_level
    - Create course (verify auto-set to secondary)
    - Verify course appears in secondary-level student discovery
    - Verify course does not appear in basic-level student discovery
    - _Requirements: 4.1, 7.1, 8.1_

  - [ ] 11.3 Integration test: Enrollment and recommendations flow
    - Create mixed courses at both levels
    - Register student at basic level
    - Get recommendations (verify basic-level only)
    - Enroll in recommended course
    - Get recommendations again (verify enrolled course excluded)
    - _Requirements: 6.1, 6.2, 6.3_

- [ ] 12. Checkpoint - All tests passing
  - Ensure all unit tests pass (education level validation, filtering, error cases)
  - Ensure all property-based tests pass (100+ iterations each)
  - Ensure all integration tests pass
  - Verify no warnings or errors in test output
  - Ask user if questions arise

- [ ] 13. Add error handling and edge case coverage
  - [ ] 13.1 Handle concurrent enrollment attempts (prevent race conditions)
    - Test duplicate enrollment prevention logic
    - Verify only one enrollment record created
    - _Requirements: 5.3_

  - [ ] 13.2 Add proper error messages for all validation failures
    - Education level required: "Education level is required"
    - Invalid level: "Invalid education level. Use 'basic' or 'secondary'"
    - Enrollment mismatch: "Course is not available for your education level"
    - Tutor level mismatch: "Course education level must match your education level"
    - Tutor missing level: "Please set your education level first"
    - Test each error message in actual responses
    - _Requirements: 1.5, 5.2, 7.2, 7.4_

- [ ] 14. Final checkpoint - System validation
  - Verify all 10 correctness properties are tested
  - Verify all 10 requirements have corresponding tasks
  - Run full test suite: unit + property + integration tests
  - Verify no null pointer or undefined education_level errors
  - Verify audit logs contain all required information
  - Ask user if all requirements are met

---

## Notes

### Property-Based Test Framework

- Use existing test framework from project (likely PHPUnit or similar)
- Run each property test minimum 100 iterations
- Configure test generators to cover edge cases:
  - Empty strings for education_level
  - Boundary values (very long strings, special characters)
  - Various level combinations (basic+basic, secondary+secondary, mixed, null)
  - Large datasets (multiple students/courses/tutors)

### Task Dependencies

```
Phase 1: Foundation
├─ 1 (User level support)
├─ 2 (Course level enforcement)
└─ 3 (Course filtering)

Phase 2: Core Features
├─ 4 (Enrollment validation) [depends on 1, 2, 3]
├─ 5 (Tutor discovery) [depends on 1]
├─ 6 (Recommendations) [depends on 2, 3]
└─ 7 (Round-trip tests) [depends on 1]

Phase 3: Testing & Edge Cases
├─ 8 (Migration compatibility) [depends on 1, 2]
├─ 9 (Filter commutativity) [depends on 3, 6]
├─ 10 (API exposure) [depends on all core tasks]
├─ 11 (Integration tests) [depends on all core tasks]
└─ 12-14 (Final validation)
```

### Optional vs Required Tasks

**Optional Tasks** (marked with *): Property and property-related tests
- Can be skipped for MVP but strongly recommended for correctness
- Property tests provide comprehensive coverage with minimal code
- Unit tests should still be written for all functionality

**Required Tasks**: All implementation and basic testing
- Core functionality changes are required
- Must complete before property tests make sense

### Testing Approach

1. **Property-Based First**: Write property tests early to document invariants
2. **Example-Based**: Support properties with concrete unit tests
3. **Integration-Focused**: Verify end-to-end flows work correctly

### Files to Modify

- `app/Http/Controllers/Api/AuthController.php` - Add validation to register
- `app/Http/Controllers/Api/CourseController.php` - Add filtering, error codes, logging
- `app/Http/Controllers/Api/EnrollmentController.php` - Change 403→409, add logging
- `app/Http/Controllers/Api/TutorController.php` - Add tutor discovery filtering
- `app/Models/User.php` - Already has education_level, may add helper methods
- `app/Models/Course.php` - Already has education_level, may add helper methods
- Test files - Create comprehensive test suite

### Migration Verification

✅ Migrations exist and are in place:
- `2024_01_15_add_education_level_to_users_table.php`
- `2024_01_16_add_education_level_to_courses_table.php`

Database schema ready; focus on business logic and validation.

