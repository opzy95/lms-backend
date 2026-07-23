<?php







use Illuminate\Database\Migrations\Migration;



use Illuminate\Database\Schema\Blueprint;



use Illuminate\Support\Facades\Schema;







return new class extends Migration {



    public function up()



    {



        if (!Schema::hasTable('quiz_attempts')) {
            Schema::create('quiz_attempts', function (Blueprint $table) {



                $table->id();



                $table->foreignId('quiz_id')->constrained()->onDelete('cascade');



                $table->foreignId('student_id')->constrained('users')->onDelete('cascade');



                $table->integer('score')->default(0);



                $table->integer('total_questions')->default(0);



                $table->enum('status', ['in_progress', 'pending_grading', 'graded'])->default('in_progress');



                $table->timestamp('started_at')->nullable();



                $table->timestamp('submitted_at')->nullable();



                $table->timestamps();



                



                $table->unique(['quiz_id', 'student_id']); // 1 attempt per student



            });
        }



    }



    public function down() { Schema::dropIfExists('quiz_attempts'); }



};