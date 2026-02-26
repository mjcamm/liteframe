<?php

// Test derived functions — safe to delete along with the entire tests/ directory.

function slugify(object $entity): string
{
    $title = $entity->title ?? '';
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

function reading_time(object $entity): int
{
    $body = $entity->body ?? '';
    $words = str_word_count(strip_tags($body));
    return max(1, (int) ceil($words / 200));
}
