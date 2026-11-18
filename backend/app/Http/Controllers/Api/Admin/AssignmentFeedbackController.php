<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AssignmentFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « J'aime » et commentaires du pasteur sur les reponses des membres a leur devoir (DS).
 * Droit assignments.feedback, sur les membres dont il lit deja les devoirs ; toutes les regles sont dans
 * App\Services\AssignmentFeedback.
 */
class AssignmentFeedbackController extends Controller
{
    public function like(Request $request, string $response): JsonResponse
    {
        $data = $request->validate(['liked' => ['required', 'boolean']]);
        $user = $request->user();
        $item = AssignmentFeedback::response($response);
        AssignmentFeedback::like($user, $item, (bool) $data['liked']);

        return response()->json(['feedback' => AssignmentFeedback::forResponses(collect([$item]), $user)[$item->id]]);
    }

    public function store(Request $request, string $response): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();
        $item = AssignmentFeedback::response($response);
        $comment = AssignmentFeedback::comment($user, $item, $data['body_doc'] ?? null, $data['body'] ?? null);

        return response()->json([
            'message' => 'Commentaire publié. Le membre en est prévenu.',
            'comment' => AssignmentFeedback::present($comment->load('author.profile'), $user),
        ], 201);
    }

    public function update(Request $request, string $comment): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();
        $item = AssignmentFeedback::edit($user, AssignmentFeedback::findComment($comment), $data['body_doc'] ?? null, $data['body'] ?? null);

        return response()->json([
            'message' => 'Commentaire modifié.',
            'comment' => AssignmentFeedback::present($item->load('author.profile'), $user),
        ]);
    }

    public function destroy(Request $request, string $comment): JsonResponse
    {
        AssignmentFeedback::delete($request->user(), AssignmentFeedback::findComment($comment));

        return response()->json(['message' => 'Commentaire supprimé.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'body_doc' => ['nullable', 'array', 'required_without:body'],
            'body' => ['nullable', 'string', 'max:20000', 'required_without:body_doc'],
        ], [
            'body_doc.required_without' => 'Écrivez votre commentaire.',
            'body.required_without' => 'Écrivez votre commentaire.',
        ]);
    }
}
