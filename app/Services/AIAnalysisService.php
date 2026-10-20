<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\AIAnalysisRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/AIAnalysisService.php القديمة — بند 21.
 *
 * بتنسق run تحليل AI كامل لمشروع: درجة الجاهزية، التصنيف، إمكانية
 * النجاح، اقتراحات التحسين، وملخص لغة-بسيطة — كل واحدة متفوّضة لخدمتها
 * الخاصة، وكل واحدة متسجّلة بشكل مستقل في ai_analysis (queued/processing/
 * completed/failed) عشان فشل نداء واحد ميوقفش الباقي. كمان المكان الوحيد
 * اللي بيجمع "شكل تحليل الـ AI بتاع المشروع ده دلوقتي" لعرضه، من أي حاجة
 * متخزنة بالفعل (من غير نداء AI).
 *
 * الخصوصية/الموافقة: runFullAnalysis() هي نقطة العبور الوحيدة اللي كل
 * بورتال بيمر بيها قبل ما وصف/ملفات مشروع تسيب المنصة لمزوّد الـ AI
 * الخارجي المُعد (Services\Ai\AIClient)، فحاجز الموافقة هنا مش مكرر في
 * كل كولر. الكولرز (ProjectsApiController) المفروض يكونوا وجّهوا المستخدم
 * لصندوق الموافقة على فورم Run Analysis وسجّلوها عبر recordConsent() —
 * الكلاس ده بيعيد التأكد من hasValidConsent() بنفسه وبيرمي بدل ما يشتغل
 * بصمت، عشان مستدعي مستقبلي محدش ينسى يتفقد الموافقة في الكولر.
 */
class AIAnalysisService
{
    /** analysis_type => الترتيب الثابت اللي الواجهة بتعرضهم بيه. */
    private const TYPES = ['readiness_score', 'classification', 'startup_potential', 'improvement_suggestions', 'summary'];

    /**
     * رفع الرقم ده بيجبر كل مشروع يوافق تاني في أول run جاي، حتى لو فيه
     * منحة سابقة — استخدمها لو اللي بيتبعت للمزوّد، أو المزوّد نفسه، اتغير جوهريًا.
     */
    public const CONSENT_POLICY_VERSION = '1.0';

    public function __construct(
        private AIAnalysisRepository $repo,
        private AIReadinessScoreService $readiness,
        private AIClassificationService $classification,
        private AIStartupPotentialService $startupPotential,
        private AIImprovementSuggestionService $suggestions,
        private AIProjectSummaryService $summary,
        private GithubCodeReviewService $codeReview
    ) {
    }

    /** true بمجرد ما أدمن يكون فعلًا عرّف مزوّد AI (feature flag + base URL + key + model). */
    public function isAvailable(): bool
    {
        return $this->readiness->isAvailable();
    }

    /**
     * true بس لو مالك المشروع وافق فعلًا، والموافقة دي لسه تحت
     * CONSENT_POLICY_VERSION الحالي. منحة بإصدار قديم (قبل تغيير في
     * السياسة) بتتعامل زي مفيش موافقة أصلًا — الواجهة هترجع تعرض صندوق
     * الموافقة تاني في الـ run الجاي.
     */
    public function hasValidConsent(Project $project): bool
    {
        $consent = $this->repo->consentFor((int) $project->id);
        return $consent !== null
            && $consent->revoked_at === null
            && $consent->policy_version === self::CONSENT_POLICY_VERSION;
    }

    /** توقيت منحة الموافقة الحالية الصالحة، لنص "تمت الموافقة في ..." — null لو مفيش/قديمة. */
    public function consentedAt(Project $project): ?string
    {
        if (!$this->hasValidConsent($project)) {
            return null;
        }
        $consent = $this->repo->consentFor((int) $project->id);
        return $consent?->consented_at?->toIso8601String();
    }

    /** بيسجل (أو يجدد) الموافقة لمشروع تحت إصدار السياسة الحالي. */
    public function recordConsent(Project $project, int $userId, ?string $ip): void
    {
        $this->repo->upsertConsent((int) $project->id, $userId, self::CONSENT_POLICY_VERSION, $ip);
    }

    /**
     * بتشغّل كل تحليل فرعي للمشروع. كل واحد متسجّل ومتخزّن بشكل مستقل؛
     * فشل واحد ميوقفش الباقي. بترجع ملخص نتيجة لكل نوع، الكولر يقدر
     * يعرضه/يبلّغه.
     *
     * @throws \RuntimeException لو الموافقة مش موجودة (شوف docblock الكلاس) —
     *         الكولرز المفروض يكونوا حلّوا الموافقة (recordConsent()) قبل ما ينادوا الميثود دي.
     * @return array<string,array{ok:bool,error?:string}>
     */
    public function runFullAnalysis(Project $project, ?int $requestedBy, string $locale = 'en'): array
    {
        if (!$this->hasValidConsent($project)) {
            throw new \RuntimeException(
                'AI analysis blocked: no valid data-sharing consent on file for this project. '
                    . 'The project owner must agree to the AI consent notice before an analysis can run.'
            );
        }

        // نفس ملاحظة القديمة: بيشغّل 5 نداءات AI متتالية (جاهزية، تصنيف،
        // إمكانية نجاح، اقتراحات، ملخص) — كل واحد بيحترم AI_TIMEOUT بتاع
        // config/ai.php عن طريق مهلة cURL الخاصة بيه، لكن max_execution_time
        // بتاعة PHP بتطبّق على الوقت الكلي للخمسة مجتمعين وبتقتل الريكوست
        // (من غير ما تتلقّط زي try/catch تحت) قبل ما مزوّد أبطأ يخلّص. نرفعها
        // هنا مرة، أيًا كان الكولر (API الطالب/الباحث) اللي بيشغّلها.
        // 0 = من غير حد؛ AI_TIMEOUT لكل نداء لوحده يفضل السقف الحقيقي لأي
        // نداء عالق. مأذاش لو PHP شغّالة أصلًا من غير max_execution_time
        // (زي CLI) أو لو الـ SAPI بيتجاهل set_time_limit() (بعض FPM pools).
        set_time_limit(0);

        // لغة التقرير = لغة المنصة وقت التشغيل (الكود-ريفيو والملخص والاقتراحات والقيمة العملية كلهم بنفس اللغة).
        if (in_array($locale, ['ar', 'en'], true)) {
            app()->setLocale($locale);
        }

        $projectId = (int) $project->id;
        $outcomes = [];

        $run = function (string $type, callable $fn) use ($projectId, $requestedBy, &$outcomes) {
            $log = $this->repo->startLog($projectId, $type, $requestedBy);
            try {
                $fn();
                $this->repo->completeLog($log, ['ok' => true], 'configured-provider');
                $outcomes[$type] = ['ok' => true];
            } catch (\Throwable $e) {
                $this->repo->failLog($log, $e->getMessage());
                Log::warning("AI analysis '{$type}' failed", ['project_id' => $projectId, 'error' => $e->getMessage()]);
                $outcomes[$type] = ['ok' => false, 'error' => $e->getMessage()];
            }
        };

        // مراجعة الكود الحقيقية الأول (لو فيه لينك GitHub): نتايجها (ملاحظات بملف/سطر، نقاط قوة، هيكل) بتتغذى لباقي
        // التحليلات كدليل، وبتظهر في صفحة التحليل. فشلها مش بيوقف الباقي.
        if (DB::table('project_links')->where('project_id', $projectId)->where('type', 'github')->exists()) {
            try {
                $review = $this->codeReview->run((string) $project->uuid, $project->owner_id);
                $ok = is_array($review) && ($review['status'] ?? '') === 'completed';
                $outcomes['code_review'] = $ok ? ['ok' => true] : ['ok' => false, 'error' => (string) ($review['summary'] ?? 'Code review did not complete.')];
            } catch (\Throwable $e) {
                Log::warning('AI analysis: code review step failed', ['project_id' => $projectId, 'error' => $e->getMessage()]);
                $outcomes['code_review'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $run('readiness_score', fn () => $this->readiness->analyze($project));
        $run('classification', fn () => $this->classification->classify($project));
        $run('startup_potential', fn () => $this->startupPotential->assess($project));
        $run('improvement_suggestions', fn () => $this->suggestions->suggest($project));

        // الملخص متسجّل بنصه الفعلي في raw_result (لازم لـ summaryFor())،
        // فمينفعش يستخدم الـ closure العامة $run() فوق.
        $log = $this->repo->startLog($projectId, 'summary', $requestedBy);
        try {
            $result = $this->summary->summarize($project, $locale);
            $this->repo->completeLog($log, $result, 'configured-provider');
            $outcomes['summary'] = ['ok' => true];
        } catch (\Throwable $e) {
            $this->repo->failLog($log, $e->getMessage());
            Log::warning('AI analysis summary failed', ['project_id' => $projectId, 'error' => $e->getMessage()]);
            $outcomes['summary'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        return $outcomes;
    }

    /**
     * كل حاجة متخزنة حاليًا لمشروع، من غير ما ننادي الـ AI — لعرض الصفحة
     * على GET عادي.
     */
    public function getLatest(Project $project): array
    {
        $projectId = (int) $project->id;

        return [
            'readiness'         => $this->repo->readinessFor($projectId),
            'classification'    => $this->repo->classificationFor($projectId),
            'startup_potential' => $this->repo->startupPotentialFor($projectId),
            'suggestions'       => $this->repo->suggestionsFor($projectId),
            'summary'           => $this->repo->summaryFor($projectId),
            'logs'              => $this->repo->latestLogsForProject($projectId),
        ];
    }
}
