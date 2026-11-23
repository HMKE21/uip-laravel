<?php

namespace App\Contracts;

/**
 * أي مزوّد SFU (Cloudflare Realtime أو mediasoup المستضاف عندنا). الواجهة صغيرة عن قصد:
 * بروتوكول الميديا نفسه مختلف بين المزوّدين (Cloudflare = SDP، mediasoup = RTP parameters)،
 * فكل مزوّد ليه ميثوداته الخاصة، واللي مشترك هنا هو اللي قرار التحويل والـ failover محتاجه.
 */
interface SfuProvider
{
    /** 'cloudflare' | 'selfhosted' */
    public function name(): string;

    /** مفعّل من لوحة الأدمن + بياناته (credentials / URL) مكتملة. */
    public function enabled(): bool;

    /**
     * فحص سريع إن المزوّد بيرد.
     * @return array{ok:bool,status:int,data:array<string,mixed>}
     */
    public function ping(): array;
}
