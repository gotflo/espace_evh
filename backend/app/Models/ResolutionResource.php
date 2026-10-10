<?php

namespace App\Models;

use App\Support\PlainText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** Ressource proposee par le PR pour soutenir les resolutions (lien ou video, avec son auteur). */
class ResolutionResource extends Model
{
    protected $fillable = ['theme', 'title', 'author', 'url', 'youtube_id', 'media_duration', 'note', 'status', 'position', 'created_by', 'updated_by'];

    protected $casts = ['position' => 'integer', 'media_duration' => 'integer'];

    /** Titre en texte simple (lettres stylisees et emojis retires), a l'enregistrement comme a la lecture. */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn (?string $v) => PlainText::title($v),
            set: fn (?string $v) => PlainText::title($v),
        );
    }
}
