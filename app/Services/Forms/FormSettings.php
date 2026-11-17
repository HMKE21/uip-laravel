<?php

namespace App\Services\Forms;

/** Defaults + sanitising of the per-form settings JSON. Unknown keys are dropped. */
final class FormSettings
{
    public static function defaults(string $mode): array
    {
        return [
            // who may respond: public (anyone, no login) | platform (any logged-in UIPE user) | roles (logged-in with one of allowed_roles) | invited (only invited emails)
            'access' => 'public',
            'allowed_roles' => [],
            'invited_emails' => [],
            'require_login' => false,
            // duplicate prevention
            'unique_by' => [],               // any of: user, email, phone, national_id
            'one_per_device' => false,
            'max_responses' => null,
            // after submit
            'edit_after_submit' => ['allowed' => false, 'window' => 'until_close', 'minutes' => 60],
            'confirmation_message' => '',
            'redirect_url' => '',
            'show_summary_to_respondent' => false,
            'notify_owner' => true,
            'collect_ip' => true,
            'allow_save_draft' => true,
            // exam
            'exam' => [
                'duration_minutes' => $mode === 'exam' ? 30 : 0,
                'shuffle_questions' => false,
                'shuffle_options' => false,
                'show_score' => 'immediately',    // immediately | after_close | never
                'show_correct_answers' => false,
                'passing_percent' => 50,
                'block_tab_switch' => $mode === 'exam',
                'fullscreen_required' => false,
                'block_copy_paste' => $mode === 'exam',
                'max_violations' => 0,            // 0 = just log
                'auto_submit_on_max_violations' => true,
                'one_question_at_a_time' => false,
            ],
        ];
    }

    public static function normalize(array $in, string $mode, ?array $current = null): array
    {
        $out = array_replace_recursive(self::defaults($mode), $current ?? []);
        $b = fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);

        if (isset($in['access']) && in_array($in['access'], ['public', 'platform', 'roles', 'invited'], true)) {
            $out['access'] = $in['access'];
        }
        if (isset($in['allowed_roles'])) {
            $roles = array_values(array_intersect((array) $in['allowed_roles'], (array) config('roles.available', [])));
            $out['allowed_roles'] = $roles;
        }
        if (isset($in['invited_emails'])) {
            $max = (int) config('forms.limits.max_invited_emails', 2000);
            $emails = [];
            foreach ((array) $in['invited_emails'] as $e) {
                $e = mb_strtolower(trim((string) $e));
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $emails[$e] = true;
                }
            }
            $out['invited_emails'] = array_slice(array_keys($emails), 0, $max);
        }
        foreach (['one_per_device', 'notify_owner', 'collect_ip', 'allow_save_draft', 'show_summary_to_respondent'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = $b($in[$k]);
            }
        }
        if (array_key_exists('unique_by', $in)) {
            $out['unique_by'] = array_values(array_intersect((array) $in['unique_by'], ['user', 'email', 'phone', 'national_id']));
        }
        if (array_key_exists('max_responses', $in)) {
            $n = (int) $in['max_responses'];
            $out['max_responses'] = $n > 0 ? $n : null;
        }
        if (isset($in['edit_after_submit']) && is_array($in['edit_after_submit'])) {
            $e = $in['edit_after_submit'];
            $out['edit_after_submit'] = [
                'allowed' => $b($e['allowed'] ?? false),
                'window' => in_array($e['window'] ?? '', ['until_close', 'minutes'], true) ? $e['window'] : 'until_close',
                'minutes' => max(1, min(525600, (int) ($e['minutes'] ?? 60))),
            ];
        }
        foreach (['confirmation_message' => 2000, 'redirect_url' => 500] as $k => $len) {
            if (isset($in[$k])) {
                $out[$k] = mb_substr(trim((string) $in[$k]), 0, $len);
            }
        }
        if ($out['redirect_url'] !== '' && !preg_match('#^https?://#i', $out['redirect_url'])) {
            $out['redirect_url'] = '';
        }
        if (isset($in['exam']) && is_array($in['exam'])) {
            $x = $in['exam'];
            $e = &$out['exam'];
            $e['duration_minutes'] = max(0, min(1440, (int) ($x['duration_minutes'] ?? $e['duration_minutes'])));
            foreach (['shuffle_questions', 'shuffle_options', 'show_correct_answers', 'block_tab_switch', 'fullscreen_required', 'block_copy_paste', 'auto_submit_on_max_violations', 'one_question_at_a_time'] as $k) {
                if (array_key_exists($k, $x)) {
                    $e[$k] = $b($x[$k]);
                }
            }
            if (isset($x['show_score']) && in_array($x['show_score'], ['immediately', 'after_close', 'never'], true)) {
                $e['show_score'] = $x['show_score'];
            }
            if (isset($x['passing_percent'])) {
                $e['passing_percent'] = max(0, min(100, (int) $x['passing_percent']));
            }
            if (isset($x['max_violations'])) {
                $e['max_violations'] = max(0, min(100, (int) $x['max_violations']));
            }
            unset($e);
        }
        // a login-bound rule is meaningless for fully public forms: force platform access
        if (in_array('user', $out['unique_by'], true) && $out['access'] === 'public') {
            $out['access'] = 'platform';
        }
        $out['require_login'] = $out['access'] !== 'public';
        return $out;
    }
}
