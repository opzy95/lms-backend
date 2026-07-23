namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class TutorVerificationNotification extends Notification
{
    use Queueable;

    protected $status;

    public function __construct($status)
    {
        $this->status = $status;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $message = new MailMessage;
        
        if ($this->status === 'approved') {
            return $message->subject('Welcome Aboard! Tutor Account Approved')
                           ->greeting('Hello ' . $notifiable->name . '!')
                           ->line('Great news! Our administration team has verified your documents.')
                           ->line('Your tutor account is now completely active.')
                           ->action('Go to Dashboard', url('https://yourfrontend.com'));
        }

        return $message->subject('Action Required: Tutor Verification Update')
                       ->greeting('Hello ' . $notifiable->name)
                       ->line('Your verification documents were not approved by our administrative team.')
                       ->line('Please review your profile details and re-upload valid identification documents.')
                       ->action('Re-upload Documents', url('https://yourfrontend.com'));
    }



public function verifyTutor(Request $request, $id)
{
    $request->validate([
        'action' => 'required|in:approve,reject'
    ]);

    $tutor = User::findOrFail($id);

    if ($request->action === 'approve') {
        $tutor->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        
        // Triggers email dynamically via Resend
        $tutor->notify(new TutorVerificationNotification('approved'));

        return response()->json([
            'success' => true,
            'message' => 'Tutor approved, email notification sent.'
        ], 200);
    } 
    
    $tutor->update(['verification_status' => 'rejected']);
    $tutor->notify(new TutorVerificationNotification('rejected'));

    return response()->json([
        'success' => true,
        'message' => 'Tutor rejected, notification sent.'
    ], 200);
}
}
