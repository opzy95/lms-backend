<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TutorDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TutorDocumentController extends Controller
{
    /**
     * Allowed file types (MIME types)
     */
    protected $allowedMimes = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

    /**
     * Maximum file size in MB
     */
    protected $maxFileSize = 5; // 5MB

    /**
     * Upload a document
     * POST /api/tutor/documents
     */
    public function uploadDocument(Request $request)
    {
        $user = $request->user();

        // Verify user is a tutor
        if ($user->role !== 'tutor') {
            return response()->json([
                'message' => 'Only tutors can upload documents'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:ssce,nce,degree,other',
            'document_name' => 'required|string|max:255',
            'file' => 'required|file|mimes:' . implode(',', $this->allowedMimes) . '|max:' . ($this->maxFileSize * 1024),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $filename = time() . '_' . $user->id . '_' . $file->getClientOriginalName();

            // Store file in 'tutor_documents' disk
            $filePath = $file->storeAs('tutor_documents', $filename, 'public');

            // Create document record
            $document = TutorDocument::create([
                'user_id' => $user->id,
                'document_type' => $request->document_type,
                'document_name' => $request->document_name,
                'file_path' => $filePath,
                'status' => 'pending',
                'uploaded_at' => now(),
            ]);

            return response()->json([
                'message' => 'Document uploaded successfully',
                'document' => [
                    'id' => $document->id,
                    'user_id' => $document->user_id,
                    'document_type' => $document->document_type,
                    'document_name' => $document->document_name,
                    'file_path' => $document->file_path,
                    'file_url' => Storage::disk('public')->url($document->file_path),
                    'status' => $document->status,
                    'uploaded_at' => $document->uploaded_at,
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a document (only unverified documents)
     * DELETE /api/tutor/documents/{id}
     */
    public function deleteDocument(Request $request, $id)
    {
        $user = $request->user();

        $document = TutorDocument::find($id);

        if (!$document) {
            return response()->json([
                'message' => 'Document not found'
            ], 404);
        }

        // Verify ownership
        if ($document->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow deletion of pending documents
        if ($document->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending documents can be deleted'
            ], 400);
        }

        try {
            // Delete file from storage
            if (Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            // Delete document record
            $document->delete();

            return response()->json([
                'message' => 'Document deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all documents for the authenticated tutor
     * GET /api/tutor/documents
     */
    public function getDocuments(Request $request)
    {
        $user = $request->user();

        // Verify user is a tutor
        if ($user->role !== 'tutor') {
            return response()->json([
                'message' => 'Only tutors can view documents'
            ], 403);
        }

        $documents = TutorDocument::where('user_id', $user->id)
            ->orderBy('uploaded_at', 'desc')
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'user_id' => $doc->user_id,
                    'document_type' => $doc->document_type,
                    'document_name' => $doc->document_name,
                    'file_path' => $doc->file_path,
                    'file_url' => Storage::disk('public')->url($doc->file_path),
                    'status' => $doc->status,
                    'uploaded_at' => $doc->uploaded_at,
                    'approved_at' => $doc->approved_at,
                    'admin_notes' => $doc->admin_notes,
                ];
            });

        $approvedCount = $documents->where('status', 'approved')->count();
        $pendingCount = $documents->where('status', 'pending')->count();
        $rejectedCount = $documents->where('status', 'rejected')->count();
        $hasMinimumDocs = $approvedCount >= 2;

        return response()->json([
            'message' => 'Documents retrieved successfully',
            'data' => $documents,
            'summary' => [
                'total' => $documents->count(),
                'approved' => $approvedCount,
                'pending' => $pendingCount,
                'rejected' => $rejectedCount,
                'has_minimum_documents' => $hasMinimumDocs,
            ]
        ], 200);
    }

    /**
     * Get document details and download link
     * GET /api/tutor/documents/{id}
     */
    public function getDocument(Request $request, $id)
    {
        $user = $request->user();

        $document = TutorDocument::find($id);

        if (!$document) {
            return response()->json([
                'message' => 'Document not found'
            ], 404);
        }

        // Verify ownership (tutor can view own, admin can view any)
        if ($user->id !== $document->user_id && $user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'message' => 'Document retrieved successfully',
            'data' => [
                'id' => $document->id,
                'user_id' => $document->user_id,
                'document_type' => $document->document_type,
                'document_name' => $document->document_name,
                'file_path' => $document->file_path,
                'file_url' => Storage::disk('public')->url($document->file_path),
                'status' => $document->status,
                'uploaded_at' => $document->uploaded_at,
                'approved_at' => $document->approved_at,
                'admin_notes' => $document->admin_notes,
            ]
        ], 200);
    }
}
