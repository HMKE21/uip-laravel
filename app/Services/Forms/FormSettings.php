<?php

namespace App\Services\Forms;

use App\Support\Forms\RespondentIdentity;

/** Defaults + sanitising of the per-form settings JSON. Unknown keys are dropped. */
final class FormSettings
{
    public const APPEARANCE_THEMES = ['classic', 'midnight', 'ocean', 'sunset', 'forest', 'rose', 'lavender', 'paper', 'mono', 'auto'];
    public const APPEARANCE_BACKGROUNDS = ['none', 'aurora', 'mesh', 'spotlight', 'horizon', 'dots', 'grid', 'diagonal', 'blobs', 'sunrise'];
    public const APPEARANCE_FONTS = ['default', 'serif', 'rounded', 'mono', 'cairo', 'tajawal', 'almarai', 'amiri', 'naskh', 'kufi', 'readex', 'plex', 'inter', 'poppins', 'playfair', 'jakarta'];

    /** Settings as the owner's builder may see them: the password hash is replaced by a boolean. */
    public static function publicView(?array $settings): array
    {
        $out = (array) $settings;
        $out['has_password'] = !empty($out['password_hash']);
        unset($out['password_hash'], $out['password']);
        return $out;
    }

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
            'ask_name' => true,              // legacy mirror of identity.name !== 'off'
            // data collected about the respondent by the form itself: required | optional | off  (see RespondentIdentity)
            'identity' => ['name' => 'required', 'email' => 'off', 'phone' => 'off', 'national_id' => 'off', 'name_min_words' => 2],
            'allow_save_draft' => true,
            // look of the public page (see APPEARANCE_THEMES) + small wording options
            'appearance' => ['theme' => 'classic', 'accent' => '', 'font' => 'default', 'background' => 'none', 'show_progress' => true, 'submit_label' => ''],
            'closed_message' => '',          // shown instead of the generic text when the form is closed / full
            'allow_another' => false,        // "submit another response" button after submitting
            // password gate: only the bcrypt hash is stored (never returned to the client — see publicView())
            'password_hash' => '',
            // exam
            'exam' => [
                'duration_minutes' => $mode === 'exam' ? 30 : 0,
                'shuffle_questions' => false,
                'shuffle_options' => false,
                'show_score' => 'immediately',    // immediately | after_close | never
                'show_correct_answers' => false,
                'passing_percent' => 50,
                'block_tab_switch' => $mode === 'exam',
                'fullscreen_required' => $mode === 'exam',
                'block_copy_paste' => $mode === 'exam',
                'max_violations' => $mode === 'exam' ? 3 : 0,   // 0 = just log
                'auto_submit_on_max_violations' => true,
                'one_question_at_a_time' => false,
            ],
        ];
    }

    public static function normalize(array $in, string $mode, ?array $current = null): array
    {
        $out = array_replace_recursive(self::defaults($mode), $current ?? []);
        $b = fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);
        if ($current !== null && !isset($current['identity']) && array_key_exists('ask_name', $current) && !$b($current['ask_name'])) {
            $out['identity']['name'] = 'off'; // forms saved before identity settings existed
        }

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
        if (isset($in['appearance']) && is_array($in['appearance'])) {
            $a = $in['appearance'];
            $ap = &$out['appearance'];
            if (isset($a['theme']) && in_array($a['theme'], self::APPEARANCE_THEMES, true)) {
                $ap['theme'] = $a['theme'];
            }
            if (array_key_exists('accent', $a)) {
                $ap['accent'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $a['accent']) ? strtolower((string) $a['accent']) : '';
            }
            if (isset($a['background']) && in_array($a['background'], self::APPEARANCE_BACKGROUNDS, true)) {
                $ap['background'] = $a['background'];
            }
            if (isset($a['font']) && in_array($a['font'], self::APPEARANCE_FONTS, true)) {
                $ap['font'] = $a['font'];
            }
            if (array_key_exists('show_progress', $a)) {
                $ap['show_progress'] = $b($a['show_progress']);
            }
            if (array_key_exists('submit_label', $a)) {
                $ap['submit_label'] = mb_substr(trim(strip_tags((string) $a['submit_label'])), 0, 40);
            }
            unset($ap);
        }
        if (array_key_exists('closed_message', $in)) {
            $out['closed_message'] = mb_substr(trim((string) $in['closed_message']), 0, 500);
        }
        if (array_key_exists('allow_another', $in)) {
            $out['allow_another'] = $b($in['allow_another']);
        }
        // password: a non-empty "password" sets/changes it; "clear_password" removes it; otherwise the stored hash is kept.
        // (the client never sends the hash back, and a hash coming from the client is ignored)
        $out['password_hash'] = (string) (($current ?? [])['password_hash'] ?? '');
        if (!empty($in['clear_password'])) {
            $out['password_hash'] = '';
        }
        if (isset($in['password']) && is_string($in['password']) && trim($in['password']) !== '') {
            $pw = (string) $in['password'];
            if (mb_strlen($pw) < 4 || mb_strlen($pw) > 100) {
                throw new FormException('validation_failed', 'The form password must be between 4 and 100 characters.', 422);
            }
            $out['password_hash'] = \Illuminate\Support\Facades\Hash::make($pw);
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
        if (isset($in['identity']) && is_array($in['identity'])) {
            foreach (RespondentIdentity::FIELDS as $f) {
                if (isset($in['identity'][$f]) && in_array($in['identity'][$f], RespondentIdentity::MODES, true)) {
                    $out['identity'][$f] = $in['identity'][$f];
                }
            }
            if (isset($in['identity']['name_min_words'])) {
                $out['identity']['name_min_words'] = max(1, min(4, (int) $in['identity']['name_min_words']));
            }
        } elseif (array_key_exists('ask_name', $in) && !isset($in['identity'])) { // old clients
            $out['identity']['name'] = $b($in['ask_name']) ? 'required' : 'off';
        }
        // a unique-by rule on email / mobile / national ID needs that data, so the field is forced to "required"
        $out['identity'] = array_intersect_key(RespondentIdentity::resolve(['identity' => $out['identity'], 'unique_by' => $out['unique_by']]), $out['identity']);
        $out['ask_name'] = $out['identity']['name'] !== 'off';
        // a login-bound rule is meaningless for fully public forms: force platform access
        if (in_array('user', $out['unique_by'], true) && $out['access'] === 'public') {
            $out['access'] = 'platform';
        }
        $out['require_login'] = $out['access'] !== 'public';
        return $out;
    }
}
