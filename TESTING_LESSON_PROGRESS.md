# Testing Lesson Progress Tracking

## Prerequisites
- Student must be authenticated and have `role:student`
- Student must be enrolled in the course
- Lesson must exist and be published

## Test Scenarios

### Scenario 1: Basic Progress Flow (Lesson First, Then Quiz)

**Step 1: Get Lessons**
```bash
curl -X GET "http://localhost:8000/api/student/courses/1/lessons" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
```
Expected: Returns lessons with `progress_status: "not_started"`, `lesson_read: false`, `quiz_completed: false`

**Step 2: Start Lesson**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/start" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
```
Expected Response:
```json
{
  "message": "Lesson started",
  "lesson_id": 5,
  "status": "ongoing",
  "is_ongoing": true
}
```

**Step 3: Mark Lesson as Read**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/mark-read" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
```
Expected Response:
```json
{
  "message": "Lesson marked as read",
  "lesson_id": 5,
  "lesson_read": true,
  "status": "ongoing",
  "is_fully_completed": false
}
```

**Step 4: Complete Quiz**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/complete-quiz" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"score": 85}'
```
Expected Response:
```json
{
  "message": "Quiz completed",
  "lesson_id": 5,
  "quiz_completed": true,
  "quiz_score": "85.00",
  "attempts": 1,
  "status": "finished",
  "is_fully_completed": true
}
```

**Step 5: Verify Final State**
```bash
curl -X GET "http://localhost:8000/api/student/courses/1/lessons" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
```
Expected: Lesson 5 shows `progress_status: "finished"`, `lesson_read: true`, `quiz_completed: true`

---

### Scenario 2: Quiz First, Then Lesson

**Step 1: Start Lesson** (creates progress record)
```bash
curl -X POST "http://localhost:8000/api/student/lessons/6/start" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
```
Status: `ongoing`

**Step 2: Complete Quiz**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/6/complete-quiz" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"score": 90}'
```
Expected: Status remains `ongoing` (not finished until lesson read)

**Step 3: Mark Lesson as Read**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/6/mark-read" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
```
Expected: Status changes to `finished` (because quiz already completed)

---

### Scenario 3: Permission Checks

**Test: Non-enrolled Student**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/start" \
  -H "Authorization: Bearer OTHER_STUDENT_TOKEN"
```
Expected Response: `403 - Not enrolled in this course`

**Test: Unauthenticated Request**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/start"
```
Expected Response: `401 - Unauthorized`

**Test: Non-student Role**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/start" \
  -H "Authorization: Bearer TUTOR_TOKEN"
```
Expected Response: `403 - Not authorized (missing student role)`

---

### Scenario 4: Validation Tests

**Test: Invalid Score (out of range)**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/complete-quiz" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"score": 150}'
```
Expected Response: `422 - Validation error (score must be 0-100)`

**Test: Invalid Score (non-numeric)**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/complete-quiz" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"score": "invalid"}'
```
Expected Response: `422 - Validation error`

**Test: Missing Score**
```bash
curl -X POST "http://localhost:8000/api/student/lessons/5/complete-quiz" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```
Expected Response: `422 - Score is required`

---

### Scenario 5: Idempotency Tests

**Test: Start Lesson Multiple Times**
```bash
# First call
curl -X POST "http://localhost:8000/api/student/lessons/5/start" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
# Response: status = "ongoing"

# Second call (same lesson)
curl -X POST "http://localhost:8000/api/student/lessons/5/start" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
# Response: status = "ongoing" (no change, safely idempotent)
```

**Test: Mark as Read Multiple Times**
```bash
# First call
curl -X POST "http://localhost:8000/api/student/lessons/5/mark-read" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
# Response: lesson_read = true

# Second call (same lesson)
curl -X POST "http://localhost:8000/api/student/lessons/5/mark-read" \
  -H "Authorization: Bearer YOUR_STUDENT_TOKEN"
# Response: lesson_read = true (no change, safely idempotent)
```

---

## Database Verification

### Check Progress Records Created
```sql
SELECT * FROM lesson_progress WHERE user_id = 1;
```
Should show records with status transitions

### Check Unique Constraint
```sql
-- Try to insert duplicate (will fail)
INSERT INTO lesson_progress (user_id, lesson_id, status)
VALUES (1, 5, 'not_started');
-- Error: Duplicate entry
```

### Verify Foreign Keys
```sql
-- Check cascading delete works
DELETE FROM users WHERE id = 1;
-- All lesson_progress records for user_id=1 should be deleted
```

---

## Expected Database State After Tests

After completing Scenario 1:
```
user_id | lesson_id | status   | lesson_read | quiz_completed | quiz_score | attempts | created_at | updated_at
--------|-----------|----------|-------------|----------------|------------|----------|------------|----------
1       | 5         | finished | 1           | 1              | 85.00      | 1        | ...        | ...
```

---

## Performance Checks

### Query Performance
Ensure indexes are being used:
```sql
EXPLAIN SELECT * FROM lesson_progress WHERE user_id = 1;
-- Should use key 'user_id'

EXPLAIN SELECT * FROM lesson_progress WHERE lesson_id = 5;
-- Should use key 'lesson_id'

EXPLAIN SELECT * FROM lesson_progress WHERE user_id = 1 AND lesson_id = 5;
-- Should use unique key
```

---

## Integration with Frontend

### Expected Frontend Behavior

1. **Lesson List Display**
   - Show blue badge with grade level
   - Show progress indicator (not_started → ongoing → finished)
   - Show lesson_read and quiz_completed status

2. **Lesson View Page**
   - On load: Call POST `/lessons/{id}/start`
   - After reading content: Call POST `/lessons/{id}/mark-read`
   - After quiz completion: Call POST `/lessons/{id}/complete-quiz` with score

3. **Progress Dashboard**
   - Calculate overall course progress from lesson_progress records
   - Show total quiz attempts and scores
   - Display engagement timeline

---

## Troubleshooting

### Issue: 404 Not Found
- Verify lesson_id exists and is published
- Check course_id matches the course with this lesson

### Issue: 403 Not Enrolled
- Verify student is enrolled in the course
- Check enrollment record in database

### Issue: 422 Validation Error
- For complete-quiz: ensure score is numeric and 0-100
- Check JSON Content-Type header

### Issue: 500 Server Error
- Check database connection
- Verify migration was run: `php artisan migrate:status`
- Check Laravel logs: `storage/logs/laravel.log`
