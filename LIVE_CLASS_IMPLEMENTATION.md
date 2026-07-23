# Live Class Feature - Complete Implementation

## Overview
The live class feature enables tutors to create and manage live teaching sessions, and allows students to join and participate in real-time classes using Jitsi.

## Flow
1. **Tutor creates** a live class session (scheduled status)
2. **Tutor starts** the class when ready (status changes to live)
3. **Students join** the live class and attendance is recorded
4. **Tutor ends** the class when finished (status changes to ended, attendance is finalized)

## Database Schema

### live_classes table
```
- id
- course_id (FK: courses.id)
- tutor_id (FK: users.id)
- title
- description
- room_name (unique Jitsi room identifier)
- start_time
- end_time (nullable, set when class ends)
- status (enum: scheduled, live, ended)
- created_at, updated_at
```

### live_attendances table
```
- id
- live_class_id (FK: live_classes.id)
- user_id (FK: users.id)
- joined_at (timestamp when student joins)
- left_at (nullable, timestamp when student leaves)
- duration_minutes (calculated when student leaves)
- created_at, updated_at
```

## Models

### LiveClass Model
Features:
- Relationships: tutor (BelongsTo User), course (BelongsTo Course), attendances (HasMany LiveAttendance)
- Methods:
  - `getActiveStudents()` - Students currently in class
  - `getAttendedStudents()` - Students who completed attendance
  - `isStudentInClass($userId)` - Check if student is active

### LiveAttendance Model
Features:
- Relationships: liveClass (BelongsTo), student (BelongsTo User)
- Methods:
  - `calculateDuration()` - Get attendance duration in minutes
  - `markAsLeft()` - Record student exit and calculate duration

## API Endpoints

### TUTOR ENDPOINTS (Protected: auth:sanctum, role:tutor, approved)

#### Get All Live Classes
```
GET /api/tutor/live-classes
Response: List of all live classes created by tutor
```

#### Create Live Class
```
POST /api/tutor/live-classes
Request Body: {
  "course_id": 1,
  "title": "Python Basics - Session 1",
  "description": "Introduction to Python",
  "start_time": "2026-07-20 10:00:00"
}
Response: Created live class object
```

#### Start Live Class
```
PUT /api/tutor/live-classes/{id}/start
Response: Updated live class (status = live)
```

#### End Live Class
```
PUT /api/tutor/live-classes/{id}/end
Response: Updated live class (status = ended)
Note: Automatically marks all students as left
```

#### Delete Scheduled Class
```
DELETE /api/tutor/live-classes/{id}
Response: Success message
Note: Only scheduled classes can be deleted
```

#### View Attendance
```
GET /api/tutor/live-classes/{id}/attendance
Response: {
  "class_title": "...",
  "total_students": 5,
  "data": [
    {
      "student_id": 1,
      "student_name": "John Doe",
      "student_email": "john@example.com",
      "joined_at": "2026-07-20T10:05:00Z",
      "left_at": "2026-07-20T11:00:00Z",
      "duration_minutes": 55,
      "status": "completed"
    }
  ]
}
```

### STUDENT ENDPOINTS (Protected: auth:sanctum, role:student)

#### Get Available Live Classes
```
GET /api/student/live-classes
Response: List of live classes from enrolled courses
```

#### Filter by Status
```
GET /api/student/live-classes/status/{status}
Parameters: status = scheduled, live, or ended
Response: Filtered list of live classes
```

#### Get Live Class Details
```
GET /api/student/live-classes/{id}
Response: {
  "id": 1,
  "title": "...",
  "room_name": "ife-lms-1-abc123",
  "jitsi_domain": "meet.jit.si",
  "status": "live",
  "tutor": {
    "id": 1,
    "name": "Dr. Sarah",
    "email": "sarah@..."
  },
  "is_in_class": false,
  "active_students": 3
}
```

#### Join Live Class
```
POST /api/student/live-classes/{id}/join
Response: Class details + is_in_class = true
Note: Records attendance with joined_at timestamp
Validations:
- Student must be enrolled in course
- Class must be live (not scheduled or ended)
- Student cannot join twice
```

#### Leave Live Class
```
POST /api/student/live-classes/{id}/leave
Response: {
  "message": "Successfully left the live class",
  "duration_minutes": 25
}
Note: Calculates and records attendance duration
```

## Usage Flow

### For Tutors

1. **Create a class**
   ```
   POST /api/tutor/live-classes
   {
     "course_id": 1,
     "title": "Live Session",
     "start_time": "2026-07-20 10:00:00"
   }
   ```

2. **When class time arrives, start it**
   ```
   PUT /api/tutor/live-classes/{id}/start
   ```
   - Students can now join
   - Jitsi meeting room is ready

3. **Monitor class**
   ```
   GET /api/tutor/live-classes/{id}/attendance
   ```
   - See who's currently in class
   - See attendance history

4. **End the class**
   ```
   PUT /api/tutor/live-classes/{id}/end
   ```
   - All students are marked as left
   - Attendance records are finalized

### For Students

1. **View available classes**
   ```
   GET /api/student/live-classes
   ```
   - See all upcoming, live, and past classes
   - Filter by status if needed

2. **Join a live class**
   ```
   POST /api/student/live-classes/{id}/join
   ```
   - Frontend receives room_name and jitsi_domain
   - Open Jitsi meeting with these credentials
   - Attendance is recorded

3. **Leave the class**
   ```
   POST /api/student/live-classes/{id}/leave
   ```
   - When done, student leaves
   - Duration is calculated and saved

## Frontend Integration

### Display "Join Live Class" Button
- Show on student lesson view when:
  - Student is enrolled in the course
  - There is a live (active) class for this course
  - Class status is 'live'

### When User Clicks "Join"
1. Call `POST /api/student/live-classes/{id}/join`
2. Receive room_name and jitsi_domain
3. Open Jitsi iframe:
   ```javascript
   const domain = response.data.jitsi_domain;
   const roomName = response.data.room_name;
   const url = `https://${domain}/${roomName}`;
   // Embed in iframe or open window
   ```
4. When user closes/leaves, call `POST /api/student/live-classes/{id}/leave`

## Key Features

✅ **Attendance Tracking**: Automatic join/leave times and duration calculation
✅ **Enrollment Validation**: Only enrolled students can join
✅ **Status Management**: Scheduled → Live → Ended
✅ **Access Control**: Tutors can only manage their own classes
✅ **Jitsi Integration**: Ready for real-time video conferencing
✅ **Reporting**: Tutors can view detailed attendance reports

## Installation

1. Run migration:
   ```bash
   php artisan migrate
   ```

2. The LiveClass and LiveAttendance models are automatically loaded via Laravel's model discovery

3. Routes are configured in `/routes/api.php`

## Environment Variables

```
JITSI_DOMAIN=meet.jit.si  # Default public Jitsi instance
```

To use a private Jitsi server:
```
JITSI_DOMAIN=your-jitsi-domain.com
```

## Error Handling

- **Not Enrolled**: 403 - "You are not enrolled in this course"
- **Class Ended**: 410 - "This class has ended"
- **Class Not Started**: 400 - "This class has not started yet"
- **Already in Class**: Returns success with current class data
- **Not in Class**: 400 - "You are not currently in this class"
- **Cannot Delete Live/Ended**: 400 - "Can only delete scheduled classes"

## Notes

- Room names are unique and format: `ife-lms-{course_id}-{uuid}`
- Attendance duration is calculated in minutes
- When tutor ends class, all remaining students are automatically marked as left
- Jitsi is a free, open-source video conferencing solution
- Can be customized to use a private Jitsi instance
