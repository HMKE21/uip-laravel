<?php

namespace App\Services;

use App\Events\Meetings\MeetingPollClosed;
use App\Events\Meetings\MeetingPollCreated;
use App\Events\Meetings\MeetingPollResultsUpdated;
use App\Models\Meeting;
use App\Models\MeetingPoll;
use App\Repositories\MeetingRepository;

/**
 * Meetings & Collaboration Platform — Round 7 (Collaboration Extras):
 * بند 21 (Polls). "Host/moderator can create" → canManage() بس
 * لإنشاء/قفل الاستفتاء (نفس تدرّج MeetingHostControlService)، أي
 * actor حاضر (canView) يقدر يصوّت. راجع docblock migration
 * 2026_08_31_080000 وMeetingPollVote ليه voter_key بيتخزن دايمًا حتى لو
 * anonymous.
 */
class MeetingPollService
{
    public function __construct(
        private MeetingRepository $meetings,
        private MeetingPolicyService $policy
    ) {
    }

    private function requireManager(Meeting $meeting, array $actor): void
    {
        $userId = $actor['type'] === 'participant' ? (int) str_replace('user:', '', $actor['key']) : 0;
        if (!$this->policy->canManage($meeting, $userId, $this->meetings)) {
            throw new \RuntimeException('Only the host or co-host can manage polls.');
        }
    }

    /**
     * @param array $data question, poll_type ('single_choice'|'multiple_choice'), is_anonymous?, options (string[], على الأقل اتنين)
     * @throws \RuntimeException actor مش هوست/co-host.
     * @throws \InvalidArgumentException أقل من اختيارين.
     */
    public function create(Meeting $meeting, array $actor, array $data): MeetingPoll
    {
        $this->requireManager($meeting, $actor);

        $options = array_values(array_filter(array_map('trim', $data['options'] ?? [])));
        if (count($options) < 2) {
            throw new \InvalidArgumentException('A poll needs at least two options.');
        }

        $poll = $this->meetings->createPoll([
            'meeting_id'              => $meeting->id,
            'created_by_key'          => $actor['key'],
            'created_by_display_name' => $actor['display_name'],
            'question'                => $data['question'],
            'poll_type'               => $data['poll_type'] ?? 'single_choice',
            'is_anonymous'            => (bool) ($data['is_anonymous'] ?? false),
            'status'                  => 'open',
        ]);

        foreach ($options as $index => $optionText) {
            $this->meetings->createPollOption(['poll_id' => $poll->id, 'option_text' => $optionText, 'position' => $index]);
        }

        $poll = $poll->fresh(['options']);
        event(new MeetingPollCreated($meeting->uuid, $this->present($poll)));

        return $poll;
    }

    /** @return MeetingPoll[] */
    public function listFor(Meeting $meeting): array
    {
        return $this->meetings->pollsFor($meeting->id);
    }

    /**
     * @param int[] $optionIds اختيار واحد لو single_choice (بيتحقق منها)، أو أكتر لو multiple_choice.
     * @throws \RuntimeException الاستفتاء مقفول، أو اختيار مش تابع للاستفتاء ده.
     * @throws \InvalidArgumentException أكتر من اختيار في single_choice، أو مفيش اختيارات أصلًا.
     */
    public function vote(Meeting $meeting, array $actor, MeetingPoll $poll, array $optionIds): array
    {
        if (!$poll->isOpen()) {
            throw new \RuntimeException('This poll is closed.');
        }

        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        if (empty($optionIds)) {
            throw new \InvalidArgumentException('Select at least one option.');
        }
        if ($poll->poll_type === 'single_choice' && count($optionIds) > 1) {
            throw new \InvalidArgumentException('This poll only allows a single choice.');
        }

        foreach ($optionIds as $optionId) {
            if (!$this->meetings->findPollOption($poll->id, $optionId)) {
                throw new \RuntimeException('One of the selected options does not belong to this poll.');
            }
        }

        // بند 21 — إعادة التصويت بتستبدل صوت الشخص القديم في الاستفتاء
        // ده (مش تضيفه فوق) — نفس منطق toggle الـ reactions بس بمسح بدل
        // إضافة، عشان single_choice يفضل "صوت واحد فعلي" دايمًا.
        $this->meetings->deleteVotesFor($poll->id, $actor['key']);
        foreach ($optionIds as $optionId) {
            $this->meetings->createVote([
                'poll_id'            => $poll->id,
                'option_id'          => $optionId,
                'voter_key'          => $actor['key'],
                'voter_display_name' => $actor['display_name'],
            ]);
        }

        $results = $this->results($poll->fresh(['options.votes']));
        event(new MeetingPollResultsUpdated($meeting->uuid, $poll->id, $results));

        return $results;
    }

    /** @throws \RuntimeException actor مش هوست/co-host. */
    public function close(Meeting $meeting, array $actor, MeetingPoll $poll): array
    {
        $this->requireManager($meeting, $actor);

        $poll->status = 'closed';
        $poll->closed_at = now();
        $poll->save();

        $results = $this->results($poll->fresh(['options.votes']));
        event(new MeetingPollClosed($meeting->uuid, $poll->id, $results));

        return $results;
    }

    /**
     * بند 21 — "Display results live." عدد/نسبة الأصوات لكل اختيار.
     * voter_display_name بترجع بس لو مش anonymous (راجع docblock
     * migration الملف).
     *
     * @return array{poll_id:int, question:string, poll_type:string, is_anonymous:bool, status:string, total_votes:int, options:array}
     */
    public function results(MeetingPoll $poll): array
    {
        $poll->loadMissing('options.votes');
        $totalVotes = $poll->options->sum(fn ($option) => $option->votes->count());

        return [
            'poll_id'      => $poll->id,
            'question'     => $poll->question,
            'poll_type'    => $poll->poll_type,
            'is_anonymous' => (bool) $poll->is_anonymous,
            'status'       => $poll->status,
            'total_votes'  => $totalVotes,
            'options'      => $poll->options->map(function ($option) use ($poll, $totalVotes) {
                $voteCount = $option->votes->count();

                return [
                    'id'          => $option->id,
                    'option_text' => $option->option_text,
                    'vote_count'  => $voteCount,
                    'percentage'  => $totalVotes > 0 ? round(($voteCount / $totalVotes) * 100, 1) : 0.0,
                    'voters'      => $poll->is_anonymous ? null : $option->votes->pluck('voter_display_name')->values()->all(),
                ];
            })->all(),
        ];
    }

    /** شكل present() لحدث "poll created" — نفس شكل results() بالظبط، بس مفيش أصوات لسه (استفتاء جديد). */
    public function present(MeetingPoll $poll): array
    {
        return $this->results($poll);
    }
}
