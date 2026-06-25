<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class AdminController extends Controller
{
    public function approveTutor($id)
    {
        $tutor = User::where('id', $id)
        ->where ('role','tutor')
        ->first();
        
        if (!$tutor){
            return response()->json(['message'=>'Tutor not found'], 404);
        }
        if ($tutor ->is_approved){
            return response()->json(['message'=>'Tutor is already approved'], 200);
        }
        $tutor ->is_approved = true;
        $tutor->role = 'tutor.admin';
        $tutor -> save();
        return response()->json([
            'message'=>'Tutor approved successfully',
            'tutor'=>[
                'id'=> $tutor->id,
                'name'=> $tutor->name,
                'email'=> $tutor->email,
                'role'=> $tutor->role,
                'is_approved'=> $tutor->is_approved
            ]
        ], 200);
    }
    public function pendingTutors()
    {
        $tutors=User::where('role','tutor')
        ->where('is_approved',false)
        ->select('id','name','email','created_at')
        ->get();
        return response()->json($tutors);
    }
}
