# Frontend Integration Guide - Live Classes

## Overview
This guide helps frontend developers integrate the live class feature into the student and tutor interfaces.

## Required Backend Setup
1. ✅ Migration has been created: `2026_07_16_create_live_attendances_table.php`
2. ✅ Models created: `LiveClass.php`, `LiveAttendance.php`
3. ✅ Controller created: `LiveClassController.php` with all endpoints
4. ✅ Routes configured in `/routes/api.php`

**Action**: Run `php artisan migrate` to create the live_attendances table

## Student UI - Join Live Class Button

### Where to Add the Button
On the student lesson view page, add a "Join Live Class" button next to the lesson content.

**Conditions to Show Button:**
```javascript
// Show button only if:
1. Student is viewing a lesson
2. The course has a live class that is currently active (status = 'live')
3. Student is enrolled in the course (already checked by route)
```

### Implementation Steps

#### 1. Fetch Available Live Classes for a Course
```javascript
// When student enters a course lesson
async function checkForLiveClass(courseId) {
  try {
    // Get all live classes for enrolled courses
    const response = await fetch('/api/student/live-classes', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const data = await response.json();
    
    // Filter for this course and status='live'
    const activeLiveClass = data.data.find(
      cls => cls.course_id === courseId && cls.status === 'live'
    );
    
    // Show button if there's an active live class
    if (activeLiveClass) {
      showJoinLiveClassButton(activeLiveClass);
    }
  } catch (error) {
    console.error('Error fetching live classes:', error);
  }
}
```

#### 2. Join Live Class
```javascript
async function joinLiveClass(liveClassId) {
  try {
    // Join the live class and record attendance
    const response = await fetch(
      `/api/student/live-classes/${liveClassId}/join`,
      {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` }
      }
    );
    
    const data = await response.json();
    
    if (response.ok) {
      // Get Jitsi meeting details
      const { room_name, jitsi_domain } = data.data;
      
      // Open Jitsi meeting
      openJitsiMeeting(room_name, jitsi_domain);
      
      // Store liveClassId for leave button
      window.currentLiveClassId = liveClassId;
    } else {
      showError(data.message);
    }
  } catch (error) {
    console.error('Error joining live class:', error);
  }
}
```

#### 3. Open Jitsi Meeting
```javascript
function openJitsiMeeting(roomName, jitsiDomain = 'meet.jit.si') {
  // Option 1: Open in new window
  const jitsiUrl = `https://${jitsiDomain}/${roomName}`;
  window.open(jitsiUrl, 'jitsi-window', 'width=1200,height=800');
  
  // Option 2: Embed as iframe (if using LibJitsi API)
  const container = document.getElementById('jitsi-container');
  const options = {
    roomName: roomName,
    parentNode: container,
    userInfo: {
      displayName: currentUserName, // Get from auth
      email: currentUserEmail
    }
  };
  
  // Requires: <script src="https://meet.jit.si/external_api.js"></script>
  // const api = new JitsiMeetExternalAPI(jitsiDomain, options);
}
```

#### 4. Leave Live Class
```javascript
// When student closes Jitsi window or clicks "Leave"
async function leaveLiveClass() {
  try {
    const liveClassId = window.currentLiveClassId;
    
    const response = await fetch(
      `/api/student/live-classes/${liveClassId}/leave`,
      {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` }
      }
    );
    
    const data = await response.json();
    
    if (response.ok) {
      console.log(`Left class. Duration: ${data.duration_minutes} minutes`);
      // Hide Jitsi window / show lesson view again
      closeJitsiMeeting();
    } else {
      console.error(data.message);
    }
  } catch (error) {
    console.error('Error leaving live class:', error);
  }
}
```

### Complete Button Component Example

```vue
<!-- StudentLessonView.vue -->
<template>
  <div class="lesson-container">
    <div class="lesson-header">
      <h2>{{ lesson.title }}</h2>
      
      <!-- Join Live Class Button -->
      <button 
        v-if="hasActiveLiveClass"
        @click="joinLiveClass"
        class="btn btn-primary btn-lg"
        :disabled="isJoining"
      >
        <i class="fas fa-video"></i>
        {{ isInClass ? 'You are in Live Class' : 'Join Live Class Now' }}
      </button>
      
      <button
        v-if="isInClass"
        @click="leaveLiveClass"
        class="btn btn-danger"
        :disabled="isLeaving"
      >
        Leave Class
      </button>
    </div>
    
    <div class="lesson-content">
      <!-- Lesson content here -->
    </div>
  </div>
</template>

<script>
export default {
  name: 'StudentLessonView',
  data() {
    return {
      lesson: {},
      liveClass: null,
      isInClass: false,
      isJoining: false,
      isLeaving: false,
      pollInterval: null
    };
  },
  computed: {
    hasActiveLiveClass() {
      return this.liveClass && this.liveClass.status === 'live';
    }
  },
  mounted() {
    this.loadLesson();
    this.checkForLiveClass();
    
    // Poll for live classes every 30 seconds
    this.pollInterval = setInterval(
      () => this.checkForLiveClass(),
      30000
    );
  },
  beforeUnmount() {
    if (this.pollInterval) {
      clearInterval(this.pollInterval);
    }
    if (this.isInClass) {
      this.leaveLiveClass();
    }
  },
  methods: {
    async loadLesson() {
      // Load lesson details
    },
    
    async checkForLiveClass() {
      try {
        const response = await this.$http.get('/api/student/live-classes');
        const activeLiveClass = response.data.data.find(
          cls => cls.course_id === this.lesson.course_id && 
                 cls.status === 'live'
        );
        this.liveClass = activeLiveClass || null;
      } catch (error) {
        console.error('Error checking for live class:', error);
      }
    },
    
    async joinLiveClass() {
      if (!this.liveClass) return;
      
      this.isJoining = true;
      try {
        const response = await this.$http.post(
          `/api/student/live-classes/${this.liveClass.id}/join`
        );
        
        this.isInClass = true;
        
        // Open Jitsi meeting
        const { room_name, jitsi_domain } = response.data.data;
        this.openJitsiMeeting(room_name, jitsi_domain);
        
      } catch (error) {
        this.$notify.error(error.response?.data?.message || 'Failed to join');
      } finally {
        this.isJoining = false;
      }
    },
    
    async leaveLiveClass() {
      if (!this.liveClass) return;
      
      this.isLeaving = true;
      try {
        const response = await this.$http.post(
          `/api/student/live-classes/${this.liveClass.id}/leave`
        );
        
        this.isInClass = false;
        this.$notify.success('Left the live class');
        
      } catch (error) {
        console.error('Error leaving:', error);
      } finally {
        this.isLeaving = false;
      }
    },
    
    openJitsiMeeting(roomName, jitsiDomain) {
      const url = `https://${jitsiDomain}/${roomName}`;
      window.open(url, 'jitsi', 'width=1200,height=800');
    }
  }
};
</script>
```

## Tutor UI - Create and Manage Live Classes

### Dashboard Section: Upcoming Live Classes

```vue
<!-- TutorLiveClassDashboard.vue -->
<template>
  <div class="live-class-dashboard">
    <div class="header">
      <h3>Live Classes</h3>
      <button @click="showCreateModal = true" class="btn btn-primary">
        + Schedule Live Class
      </button>
    </div>
    
    <!-- Create/Edit Modal -->
    <div v-if="showCreateModal" class="modal">
      <form @submit.prevent="createLiveClass">
        <select v-model="form.course_id" required>
          <option value="">Select Course</option>
          <option 
            v-for="course in myCourses" 
            :key="course.id" 
            :value="course.id"
          >
            {{ course.title }}
          </option>
        </select>
        
        <input 
          v-model="form.title" 
          type="text" 
          placeholder="Class Title"
          required
        />
        
        <textarea 
          v-model="form.description" 
          placeholder="Description (optional)"
        ></textarea>
        
        <input 
          v-model="form.start_time" 
          type="datetime-local"
          required
        />
        
        <button type="submit" :disabled="isCreating">
          {{ isCreating ? 'Creating...' : 'Create' }}
        </button>
      </form>
    </div>
    
    <!-- Live Classes List -->
    <div class="classes-list">
      <div 
        v-for="liveClass in liveClasses" 
        :key="liveClass.id"
        class="class-card"
        :class="liveClass.status"
      >
        <div class="class-info">
          <h4>{{ liveClass.title }}</h4>
          <p class="status" :class="liveClass.status">
            {{ liveClass.status.toUpperCase() }}
          </p>
          <p class="time">{{ formatDateTime(liveClass.start_time) }}</p>
          <p class="students">{{ liveClass.active_students }} students active</p>
        </div>
        
        <div class="class-actions">
          <button 
            v-if="liveClass.status === 'scheduled'"
            @click="startClass(liveClass.id)"
            class="btn btn-success"
          >
            Start Class
          </button>
          
          <button 
            v-if="liveClass.status === 'live'"
            @click="endClass(liveClass.id)"
            class="btn btn-danger"
          >
            End Class
          </button>
          
          <button 
            @click="viewAttendance(liveClass.id)"
            class="btn btn-info"
          >
            Attendance
          </button>
          
          <button 
            v-if="liveClass.status === 'scheduled'"
            @click="deleteClass(liveClass.id)"
            class="btn btn-outline-danger"
          >
            Delete
          </button>
        </div>
      </div>
    </div>
    
    <!-- Attendance Modal -->
    <div v-if="showAttendanceModal" class="modal">
      <h4>{{ selectedClass.title }} - Attendance</h4>
      <table>
        <thead>
          <tr>
            <th>Student Name</th>
            <th>Email</th>
            <th>Joined</th>
            <th>Duration (mins)</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="attendance in attendanceList" :key="attendance.student_id">
            <td>{{ attendance.student_name }}</td>
            <td>{{ attendance.student_email }}</td>
            <td>{{ formatTime(attendance.joined_at) }}</td>
            <td>{{ attendance.duration_minutes }}</td>
            <td>{{ attendance.status }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      liveClasses: [],
      myCourses: [],
      showCreateModal: false,
      showAttendanceModal: false,
      selectedClass: null,
      attendanceList: [],
      isCreating: false,
      form: {
        course_id: '',
        title: '',
        description: '',
        start_time: ''
      }
    };
  },
  mounted() {
    this.loadLiveClasses();
    this.loadMyCourses();
    
    // Refresh live classes every 10 seconds
    setInterval(() => this.loadLiveClasses(), 10000);
  },
  methods: {
    async loadLiveClasses() {
      try {
        const response = await this.$http.get('/api/tutor/live-classes');
        this.liveClasses = response.data.data;
      } catch (error) {
        console.error('Error loading live classes:', error);
      }
    },
    
    async loadMyCourses() {
      try {
        const response = await this.$http.get('/api/tutor/my-courses');
        this.myCourses = response.data.data;
      } catch (error) {
        console.error('Error loading courses:', error);
      }
    },
    
    async createLiveClass() {
      this.isCreating = true;
      try {
        await this.$http.post('/api/tutor/live-classes', this.form);
        this.$notify.success('Live class created');
        this.showCreateModal = false;
        this.form = { course_id: '', title: '', description: '', start_time: '' };
        this.loadLiveClasses();
      } catch (error) {
        this.$notify.error(error.response?.data?.message);
      } finally {
        this.isCreating = false;
      }
    },
    
    async startClass(id) {
      try {
        await this.$http.put(`/api/tutor/live-classes/${id}/start`);
        this.$notify.success('Class started');
        this.loadLiveClasses();
      } catch (error) {
        this.$notify.error('Failed to start class');
      }
    },
    
    async endClass(id) {
      if (!confirm('End this class?')) return;
      
      try {
        await this.$http.put(`/api/tutor/live-classes/${id}/end`);
        this.$notify.success('Class ended');
        this.loadLiveClasses();
      } catch (error) {
        this.$notify.error('Failed to end class');
      }
    },
    
    async deleteClass(id) {
      if (!confirm('Delete this class?')) return;
      
      try {
        await this.$http.delete(`/api/tutor/live-classes/${id}`);
        this.$notify.success('Class deleted');
        this.loadLiveClasses();
      } catch (error) {
        this.$notify.error('Failed to delete class');
      }
    },
    
    async viewAttendance(id) {
      try {
        const response = await this.$http.get(`/api/tutor/live-classes/${id}/attendance`);
        this.selectedClass = this.liveClasses.find(c => c.id === id);
        this.attendanceList = response.data.data;
        this.showAttendanceModal = true;
      } catch (error) {
        this.$notify.error('Failed to load attendance');
      }
    },
    
    formatDateTime(datetime) {
      return new Date(datetime).toLocaleString();
    },
    
    formatTime(datetime) {
      return new Date(datetime).toLocaleTimeString();
    }
  }
};
</script>
```

## API Response Examples

### Join Live Class Response
```json
{
  "message": "Successfully joined the live class",
  "data": {
    "id": 1,
    "course_id": 5,
    "title": "Python Basics",
    "room_name": "ife-lms-5-abc123def456",
    "jitsi_domain": "meet.jit.si",
    "status": "live",
    "is_in_class": true,
    "active_students": 3
  }
}
```

### Attendance Report Response
```json
{
  "message": "Attendance retrieved successfully",
  "class_title": "Python Basics - Session 1",
  "total_students": 15,
  "data": [
    {
      "student_id": 5,
      "student_name": "John Doe",
      "student_email": "john@example.com",
      "joined_at": "2026-07-20T10:05:00Z",
      "left_at": "2026-07-20T11:30:00Z",
      "duration_minutes": 85,
      "status": "completed"
    },
    {
      "student_id": 6,
      "student_name": "Jane Smith",
      "student_email": "jane@example.com",
      "joined_at": "2026-07-20T10:10:00Z",
      "left_at": null,
      "duration_minutes": null,
      "status": "active"
    }
  ]
}
```

## Error Handling

Always handle these common errors:

```javascript
try {
  // API call
} catch (error) {
  const status = error.response?.status;
  const message = error.response?.data?.message;
  
  if (status === 403) {
    // Not authorized / not enrolled
    showError('You do not have permission');
  } else if (status === 410) {
    // Class has ended
    showError('This class has ended');
  } else if (status === 400) {
    // Bad request - check message
    showError(message);
  } else {
    showError('An error occurred');
  }
}
```

## Testing Checklist

- [ ] Student can see "Join Live Class" button when class is live
- [ ] Student can join active live class
- [ ] Jitsi meeting opens with correct room name
- [ ] Student can leave live class
- [ ] Attendance is recorded with join/leave times
- [ ] Duration is calculated correctly
- [ ] Tutor can see real-time attendance count
- [ ] Tutor can view detailed attendance report
- [ ] Tutor can end class and all students are marked as left
- [ ] Multiple students can join same class
- [ ] Error messages display correctly

## Support

For issues or questions, refer to `LIVE_CLASS_IMPLEMENTATION.md` for backend documentation.
