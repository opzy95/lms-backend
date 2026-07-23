# Lesson Progress Tracking - Quick Reference Guide

## 🎯 At a Glance

A complete lesson progress tracking system that monitors student engagement with lessons.

**Track:** Lesson viewing + Quiz completion with automatic status management.

---

## 📚 Core Concepts

| Concept | Definition |
|---------|-----------|
| **Status** | `not_started` → `ongoing` → `finished` |
| **lesson_read** | Boolean: Student viewed/read the lesson content |
| **quiz_completed** | Boolean: Student completed the associated quiz |
| **quiz_score** | Decimal: Student's quiz score (0-100) |
| **attempts** | Integer: Number of times quiz was attempted |
| **Fully Completed** | Status is `finished` AND lesson_read AND quiz_completed |

---

## 🔌 API Endpoints

### Base URL: `/api/student/`
All endpoints require: `auth:sanctum` + `role:student`

| Method | Endpoint | Purpose |
|--------|----------|---------|
| **GET** | `/courses/{course_id}/lessons` | List lessons with progress |
| **POST** | `/lessons/{lesson_id}/start` | Start lesson (→ ongoing) |
| **POST** | `/lessons/{lesson_id}/mark-read` | Mark content as read |
| **POST** | `/lessons/{lesson_id}/complete-quiz` | Record quiz completion |

---

## 📤 Request/Response Quick Examples

### 1. Get Lessons
```bash
GET /api/student/courses/1/lessons
```
Returns: List of lessons with `progress_status`, `is_ongoing`, `lesson_read`, `quiz_completed`

### 2. Start Lesson
```bash
POST /api/student/lessons/5/start
```
Response: `{"status": "ongoing", "is_ongoing": true}`

### 3. Mark as Read
```bash
POST /api/student/lessons/5/mark-read
```
Response: `{"lesson_read": true, "status": "ongoing|finished"}`

### 4. Complete Quiz
```bash
POST /api/student/lessons/5/complete-quiz
Content-Type: application/json

{"score": 85}
```
Response: `{"quiz_completed": true, "status": "finished", "is_fully_completed": true}`

---

## 🗂️ Database Schema

**Table:** `lesson_progress`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | Primary key |
| user_id | bigint | FK → users |
| lesson_id | bigint | FK → lessons |
| status | enum | not_started, ongoing, finished |
| lesson_read | boolean | Default: false |
| quiz_completed | boolean | Default: false |
| quiz_score | decimal(5,2) | Nullable |
| attempts | int | Default: 0 |
| created_at | timestamp | |
| updated_at | timestamp | |

**Constraints:**
- Unique: (user_id, lesson_id)
- Indexes: user_id, lesson_id

---

## 🔄 Status Logic at a Glance

```
not_started ─startLesson()→ ongoing
                              ↓
                ┌─────────────┴──────────────┐
                ↓                            ↓
         markLessonRead()          markQuizCompleted()
              ↓ (if quiz done)          ↓ (if lesson read)
           finished                   finished
              ↑                            ↑
              └─────────────┬──────────────┘
                    Both conditions met
```

---

## 💻 Frontend Integration Example

```javascript
// 1. When lesson page loads
await fetch('/api/student/lessons/5/start', { method: 'POST' });

// 2. When student finishes reading content
await fetch('/api/student/lessons/5/mark-read', { method: 'POST' });

// 3. When student completes quiz
await fetch('/api/student/lessons/5/complete-quiz', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ score: 85 })
});

// 4. Refresh lesson list to see updated progress
const response = await fetch('/api/student/courses/1/lessons');
const { lessons } = await response.json();
// lessons[i].progress_status will be 'finished'
```

---

## ✅ Validation Rules

| Field | Rule |
|-------|------|
| `score` | Required, numeric, 0-100 |
| `lesson_id` | Must exist, must be published |
| `course_id` | Student must be enrolled |
| `user_id` | Must be authenticated student |

---

## 🛡️ Error Responses

| Status | Error | Cause |
|--------|-------|-------|
| 401 | Unauthorized | No auth token |
| 403 | Not enrolled | Student not in course |
| 404 | Not found | Lesson doesn't exist |
| 422 | Validation error | Invalid score value |

---

## 📊 Response Status Codes

| Code | Scenario |
|------|----------|
| 200 | Success (GET/POST) |
| 201 | Created (POST) |
| 400 | Bad request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not found |
| 422 | Validation error |
| 500 | Server error |

---

## 🎓 Real-World Scenario

**Student Maria takes Lesson 5:**

```
1. Opens lesson → GET /courses/1/lessons → progress_status: "not_started"
2. Starts viewing → POST /lessons/5/start → status: "ongoing"
3. Finishes reading → POST /lessons/5/mark-read → lesson_read: true
4. Takes quiz → Quiz API handles submission
5. Completes quiz → POST /lessons/5/complete-quiz (score: 92)
   → status: "finished", is_fully_completed: true
6. Next login → GET /courses/1/lessons → lesson shows as COMPLETED
```

---

## 🔧 Model Helper Methods

Available in LessonProgress model:

```php
// Get existing or create new
$progress = LessonProgress::getOrCreate($user_id, $lesson_id);

// Mark lesson as started
$progress->markStarted();

// Mark content as read
$progress->markLessonRead();

// Mark quiz complete
$progress->markQuizCompleted($score);

// Check if fully completed
if ($progress->isFullyCompleted()) { }
```

---

## 📈 Key Features

- ✅ Automatic status transitions
- ✅ Duplicate prevention (unique constraint)
- ✅ Enrollment verification
- ✅ Input validation
- ✅ Idempotent operations (safe to retry)
- ✅ Cascading deletes
- ✅ Optimized queries with indexes
- ✅ Complete audit trail (timestamps)

---

## 🚀 Getting Started

1. **Migration:** Already run → `php artisan migrate`
2. **Use the API:** Call endpoints from frontend
3. **Monitor Progress:** Query lesson_progress table
4. **Frontend:** Integrate the 3 POST endpoints

---

## 📖 Documentation Files

1. **IMPLEMENTATION_SUMMARY.md** - Complete overview
2. **LESSON_PROGRESS_IMPLEMENTATION.md** - Detailed architecture
3. **TESTING_LESSON_PROGRESS.md** - Test scenarios & examples
4. **QUICK_REFERENCE.md** - This file

---

## 🎯 Common Tasks

### Get student's lesson progress
```php
$progress = LessonProgress::where('user_id', $user_id)
    ->where('lesson_id', $lesson_id)
    ->first();
```

### Get all lessons for a student
```php
$lessons = LessonProgress::where('user_id', $user_id)
    ->with('lesson')
    ->get();
```

### Get completed lessons in a course
```php
$completed = LessonProgress::where('user_id', $user_id)
    ->where('status', 'finished')
    ->whereHas('lesson', fn($q) => $q->where('course_id', $course_id))
    ->count();
```

### Calculate progress percentage
```php
$total = Lesson::where('course_id', $course_id)->count();
$completed = LessonProgress::where('user_id', $user_id)
    ->where('status', 'finished')
    ->whereHas('lesson', fn($q) => $q->where('course_id', $course_id))
    ->count();
$percentage = ($completed / $total) * 100;
```

---

## 🆘 Troubleshooting

| Issue | Solution |
|-------|----------|
| 403 error | Verify student is enrolled in course |
| 404 error | Check lesson_id exists and is published |
| 422 error | Ensure score is 0-100 and numeric |
| Status not updating | Check if student enrolled + lesson published |
| Duplicate records | Should not happen (unique constraint) |

---

**Last Updated:** July 9, 2026  
**Version:** 1.0.0  
**Status:** Production Ready ✅
