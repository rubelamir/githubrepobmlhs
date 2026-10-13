<?php
declare(strict_types=1);

function writeAuditLog(PDO $db, string $action, string $entity, ?int $entityId, string $summary): void
{
    if (!function_exists('tableExists') || !tableExists($db, 'audit_log')) {
        return;
    }
    $actorName = '';
    $actorId = 0;
    if (function_exists('getAuthenticatedTeacher')) {
        $teacher = getAuthenticatedTeacher();
        if (is_array($teacher)) {
            $actorId = (int) ($teacher['id'] ?? 0);
            $actorName = trim((string) ($teacher['teacher_name'] ?? ''));
        }
    }
    $db->prepare(
        'INSERT INTO audit_log (actor_id, actor_name, action_key, entity_name, entity_id, summary)
         VALUES (:actor_id, :actor_name, :action_key, :entity_name, :entity_id, :summary)'
    )->execute([
        'actor_id' => $actorId > 0 ? $actorId : null,
        'actor_name' => $actorName !== '' ? $actorName : null,
        'action_key' => substr($action, 0, 40),
        'entity_name' => substr($entity, 0, 40),
        'entity_id' => $entityId !== null && $entityId > 0 ? $entityId : null,
        'summary' => function_exists('mb_substr') ? mb_substr($summary, 0, 255, 'UTF-8') : substr($summary, 0, 255),
    ]);
}

function officeToolsAllowed(): bool
{
    if (!function_exists('isHeadAdmin') || !function_exists('canAccessModule')) {
        return false;
    }

    return isHeadAdmin()
        || canAccessModule('school-settings')
        || canAccessModule('notices')
        || canAccessModule('admissions')
        || canAccessModule('result-publication');
}
