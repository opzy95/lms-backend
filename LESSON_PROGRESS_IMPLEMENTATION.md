# Lesson Progress Tracking Implementation

## Overview
Complete implementation of lesson progress tracking system to monitor student engagement with courses and lessons.

## Database Changes

### Migration: `2026_07_09_130207_create_lesson_progress_table.php`
Created `lesson_progress` table with the following schema:

**Columns:**
- `id` - Primary key
- `user_id` - Foreign key to users table (CASCADE delete)
- `lesson_id` - Foreign key to lessons table (CASCADE delete)
- `status` - Enum: 'not_started' (default), 'ongoing', 'finished'
- `lesson_read` - Boolean: tracks if student read the lesson (default: false)
- `quiz_completed` - Boolean: tracks if student completed quiz (default: false)
- `quiz_score` - Decimal(5,2): stores quiz score (nullable)
- `attempts` - Integer: number of quiz attempts (default: 0)
- `created_at` - Timestamp
- `updated_at` - Timestamp

**Constraints:**
- Unique constraint on (user_id, lesson_id) - ensures one record per student per lesson
- Indexes on user_id and lesson_id for performance

## Model Changes

### New Model: `app/Models/LessonProgress.php`
Full model with relationships and helper methods.

**Relationships:**
- `user()` - BelongsTo User
- `lesson()` - BelongsTo Lesson

**Helper Methods:**

1. **`markStarted()`**
   - Changes status from "not_started" to "ongoing"
   - Only updates if currently "not_started"

2. **`markLessonRead()`**
   - Sets lesson_read flag to true
   - Automatically marks as "finished" if quiz is already completed
   - Otherwise remains in current status

3. **`markQuizCompleted(float $score)`**
   - Sets quiz_completed flag to true
   - Increments attempts counter
   - Stores quiz score
   - Automatically marks as "finished" if lesson was already read
   - Otherwise sets to "ongoing" if not already finished

4. **`isFullyCompleted(): bool`**
   - Returns true if status is "finished" AND lesson_read AND quiz_completed
   - Indicates complete engagement with lesson

5. **`getOrCreate($user_id, $lesson_id): self`**
   - Static method to get existing or create new progress record
   - Ensures one record per student-lesson pair
   - Default status is "not_started"

### Updated Model: `app/Models/Lesson.php`
Added relationship:
- `progressRecords()` - HasMany LessonProgress

## Controller Changes

### Updated: `app/Http/Controllers/Api/StudentController.php`

#### Import Addition
- Added `use App\Models\LessonProgress;`

#### Updated Method: `courseLessons($course_id)`
Now includes progress tracking data in response:

**Additional Response Fields:**
- `progress_status` - Current status (not_started, ongoing, finished)
- `is_ongoing` - Boolean flag for ongoing status
- `lesson_read` - Whether student has read the lesson
- `quiz_completed` - Whether student has completed the quiz

**Behavior:**
- Gets or creates progress record for each lesson
- Maintains all previous functionality
- Filters for published lessons only
- Orders by lesson order, then created_at

#### New Method: `startLesson($lesson_id)`
**Endpoint:** `POST /student/lessons/{lesson_id}/start`

**Functionality:**
- Marks lesson as started (status → ongoing)
- Verifies student is enrolled in course
- Gets or creates progress record
- Calls `markStarted()` helper

**Response:**
```json
{
  "message": "Lesson started",
  "lesson_id": 123,
  "status": "ongoing",
  "is_ongoing": true
}
```

#### New Method: `markLessonRead($lesson_id)`
**Endpoint:** `POST /student/lessons/{lesson_id}/mark-read`

**Functionality:**
- Marks lesson content as read
- Verifies student is enrolled in course
- Calls `markLessonRead()` helper
- Auto-completes lesson if quiz already completed

**Response:**
```json
{
  "message": "Lesson marked as read",
  "lesson_id": 123,
  "lesson_read": true,
  "status": "ongoing|finished",
  "is_fully_completed": true|false
}
```

#### New Method: `completeQuiz(Request $request, $lesson_id)`
**Endpoint:** `POST /student/lessons/{lesson_id}/complete-quiz`

**Validation:**
- `score` - Required, numeric, 0-100

**Functionality:**
- Records quiz completion with score
- Verifies student is enrolled in course
- Increments attempt counter
- Calls `markQuizCompleted($score)` helper
- Auto-completes lesson if content already read

**Request Body:**
```json
{
  "score": 85.5
}
```

**Response:**
```json
{
  "message": "Quiz completed",
  "lesson_id": 123,
  "quiz_completed": true,
  "quiz_score": 85.5,
  "attempts": 1,
  "status": "ongoing|finished",
  "is_fully_completed": true|false
}
```

## Route Changes

### Updated: `routes/api.php`

**Student Routes Prefix:** `/api/student`

**New Routes Added:**
```php
POST   /lessons/{lesson_id}/start           -> startLesson()
POST   /lessons/{lesson_id}/mark-read       -> markLessonRead()
POST   /lessons/{lesson_id}/complete-quiz   -> completeQuiz()
```

**Existing Routes Enhanced:**
```php
GET    /courses/{course_id}/lessons         -> courseLessons() (updated with progress data)
```

All student routes require:
- Authentication: `auth:sanctum`
- Role: `role:student`

## Status Transitions

### Lesson Progress Status Flow

```
┌─────────────┐
│ not_started │ (initial state)
└──────┬──────┘
       │ startLesson()
       ▼
┌─────────────┐
│   ongoing   │
└──────┬──────┘
       │
       ├─ markLessonRead() & markQuizCompleted() → finished
       │
       └─ markLessonRead() alone → ongoing
       └─ markQuizCompleted() alone → ongoing → finished (when lesson read)
```

## API Usage Examples

### 1. Get Lessons with Progress
```bash
GET /api/student/courses/1/lessons
Authorization: Bearer {token}
```

Response includes progress_status, is_ongoing, lesson_read, quiz_completed.

### 2. Start a Lesson
```bash
POST /api/student/lessons/5/start
Authorization: Bearer {token}
```

### 3. Mark Lesson as Read
```bash
POST /api/student/lessons/5/mark-read
Authorization: Bearer {token}
```

### 4. Complete Quiz
```bash
POST /api/student/lessons/5/complete-quiz
Authorization: Bearer {token}
Content-Type: application/json

{
  "score": 92.5
}
```

## Database Migration Status
✅ Migration executed successfully - table created and deployed

## Code Quality
✅ All PHP syntax validated
✅ No errors detected in StudentController
✅ No errors detected in LessonProgress model
✅ No errors detected in routes
✅ Proper foreign key relationships with CASCADE delete
✅ Unique constraints prevent duplicates

## Features Enabled
- ✅ Granular progress tracking per student-lesson
- ✅ Automatic status transitions based on engagement
- ✅ Quiz score storage and attempt tracking
- ✅ Complete engagement visibility
- ✅ Enrollment validation on all endpoints
- ✅ RESTful API design
- ✅ Proper error handling and validation
