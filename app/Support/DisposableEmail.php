<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * "Real e-mail only": rejects throw-away / temporary mailbox providers (mailinator, 10minutemail,
 * guerrillamail, temp-mail, yopmail ...) and addresses whose domain cannot receive mail at all.
 *
 *  1. The domain (or any parent of it, so x.mailinator.com is caught) is on the built-in blocklist
 *     or on config('mail.blocked_domains') / MAIL_BLOCKED_DOMAINS (comma separated, no deploy of code needed).
 *  2. The domain has no MX and no A/AAAA record => nobody can ever read mail sent there.
 *     (Skipped automatically when DNS itself looks unreachable, so an outage never locks everyone out.)
 *
 *  3. The domain is on the community list downloaded weekly into the cache by `mail:update-disposable-domains`.
 *
 * config('mail.allowed_domains') / MAIL_ALLOWED_DOMAINS always wins (escape hatch for a false positive).
 * MAIL_BLOCK_DISPOSABLE=false switches both checks off (local / automated testing only).
 *
 * On top of that, the admin controls the same rules live from Admin → Platform Policy (App\Support\PlatformPolicy):
 * a custom block list, an allow list (wins over everything), and an on/off switch per built-in check.
 */
final class DisposableEmail
{
    /** @var string[] */
    private const BLOCKED = [
        'mailinator.com', 'mailinator.net', 'mailinator.org', 'mailinator2.com', 'mailinater.com', 'binkmail.com',
        'bobmail.info', 'chammy.info', 'devnullmail.com', 'letthemeatspam.com', 'reallymymail.com', 'safetymail.info',
        'sogetthis.com', 'spamhereplease.com', 'superrito.com', 'thisisnotmyrealemail.com', 'tradermail.info', 'veryrealemail.com',
        'zippymail.info', 'notmailinator.com', 'suremail.info', '10minutemail.com', '10minutemail.net', '10minutemail.org',
        '10minutemail.co.uk', '10minutemail.de', '10minemail.com', '20minutemail.com', '20minutemail.it', '30minutemail.com',
        '60minutemail.com', '10mail.org', '10mail.com', 'minutemail.com', 'tenminutemail.com', '10minut.com',
        'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamail.biz', 'guerrillamail.de', 'guerrillamail.info',
        'guerrillamailblock.com', 'grr.la', 'sharklasers.com', 'pokemail.net', 'spam4.me', 'guerrillamail.xyz',
        'yopmail.com', 'yopmail.fr', 'yopmail.net', 'jetable.fr.nf', 'nospam.ze.tc', 'nomail.xl.cc',
        'mega.zik.dj', 'speed.1s.fr', 'courriel.fr.nf', 'moncourrier.fr.nf', 'monemail.fr.nf', 'monmail.fr.nf',
        'temp-mail.org', 'temp-mail.io', 'temp-mail.ru', 'temp-mail.com', 'tempmail.com', 'tempmail.net',
        'tempmail.org', 'tempmail.de', 'tempmail.us', 'tempmail.dev', 'tempmail.plus', 'tempmailo.com',
        'tempmailaddress.com', 'tempmail.email', 'tmpmail.org', 'tmpmail.net', 'temp-mail.live', 'tempail.com',
        'temporarymail.com', 'tempinbox.com', 'tempr.email', 'tempemail.com', 'tempemail.net', 'tempmailer.com',
        'tempmailer.de', 'tempomail.fr', 'throwawaymail.com', 'throwam.com', 'trashmail.com', 'trashmail.net',
        'trashmail.org', 'trashmail.de', 'trashmail.me', 'trashmail.io', 'trash-mail.com', 'trash-mail.de',
        'trashmail.at', 'trashmail.ws', 'trbvm.com', 'trashinbox.com', 'trashymail.com', 'getnada.com',
        'nada.email', 'nada.ltd', 'getairmail.com', 'airmail.cc', 'fakeinbox.com', 'fakemail.net',
        'fakemailgenerator.com', 'emailfake.com', 'email-fake.com', 'fake-mail.net', 'fakemail.fr', 'fakeinbox.net',
        'mailnesia.com', 'maildrop.cc', 'maildrop.gq', 'mailcatch.com', 'dispostable.com', 'discard.email',
        'discardmail.com', 'discardmail.de', 'disposable.email', 'disposableemailaddresses.com', 'disposablemail.com', 'disposable-email.ml',
        'dropmail.me', '10mail.xyz', 'mohmal.com', 'mohmal.im', 'mohmal.in', 'mohmal.tech',
        'mytemp.email', 'mytrashmail.com', 'mt2015.com', 'mt2014.com', 'mailtemp.net', 'mailtemp.info',
        'mailtothis.com', 'burnermail.io', 'anonbox.net', 'anonymbox.com', 'spamgourmet.com', 'spamgourmet.net',
        'spamgourmet.org', 'spambox.us', 'spambog.com', 'spambog.de', 'spambog.ru', 'spam.la',
        'spamfree24.org', 'spamfree24.com', 'spamfree24.de', 'spamfree24.eu', 'spamfree24.info', 'spamfree24.net',
        'spamex.com', 'spamify.com', 'spaml.com', 'spaml.de', 'spamspot.com', 'spamthis.co.uk',
        'spamtroll.net', 'spamavert.com', 'spamday.com', 'mailforspam.com', 'mailmoat.com', 'mailscrap.com',
        'mailsiphon.com', 'mailzilla.com', 'mailzilla.org', 'meltmail.com', 'mintemail.com', 'mierdamail.com',
        '33mail.com', '0-mail.com', '0815.ru', '0clickemail.com', '1secmail.com', '1secmail.net',
        '1secmail.org', '2prong.com', '3d-painting.com', '4warding.com', '4warding.net', '6ip.us',
        '7tags.com', '99experts.com', '9ox.net', 'armyspy.com', 'cuvox.de', 'dayrep.com',
        'einrot.com', 'fleckens.hu', 'gustr.com', 'jourrapide.com', 'rhyta.com', 'teleworm.us',
        'teleworm.com', 'emailondeck.com', 'email-temp.com', 'emailtemporanea.com', 'emailtemporanea.net', 'emailtemporar.ro',
        'emailtemporario.com.br', 'emailthe.net', 'emailsensei.com', 'emailsingularity.net', 'emailto.de', 'emailwarden.com',
        'emailx.at.hm', 'filzmail.com', 'fixmail.tk', 'flyspam.com', 'gishpuppy.com', 'great-host.in',
        'guerillamail.com', 'haltospam.com', 'harakirimail.com', 'hidemail.de', 'hmamail.com', 'hulapla.de',
        'ieatspam.eu', 'ieatspam.info', 'imails.info', 'inbax.tk', 'inboxalias.com', 'inboxclean.com',
        'inboxclean.org', 'incognitomail.com', 'incognitomail.net', 'incognitomail.org', 'instant-mail.de', 'jetable.com',
        'jetable.net', 'jetable.org', 'jnxjn.com', 'junk1e.com', 'kasmail.com', 'klassmaster.com',
        'klzlk.com', 'kulturbetrieb.info', 'kurzepost.de', 'lifebyfood.com', 'lookugly.com', 'lortemail.dk',
        'mail-temporaire.fr', 'mail-temporaire.com', 'mail2rss.org', 'mail333.com', 'mailbidon.com', 'mailblocks.com',
        'maileater.com', 'mailexpire.com', 'mailfa.tk', 'mailfreeonline.com', 'mailguard.me', 'mailimate.com',
        'mailin8r.com', 'mailinator.us', 'mailme24.com', 'mailmetrash.com', 'mailnator.com', 'mailorg.org',
        'mailpick.biz', 'mailquack.com', 'mailrock.biz', 'mailseal.de', 'mailslapping.com', 'mailslite.com',
        'mailtome.de', 'mailtrash.net', 'mailtv.net', 'makemetheking.com', 'manybrain.com', 'meinspamschutz.de',
        'messagebeamer.de', 'mezimages.net', 'mfsa.ru', 'moburl.com', 'mvrht.com', 'mvrht.net',
        'mypartyclip.de', 'myphantomemail.com', 'mysamp.de', 'myspaceinc.com', 'myspaceinc.net', 'myspacepimpedup.com',
        'myspamless.com', 'neverbox.com', 'nincsmail.hu', 'no-spam.ws', 'nobulk.com', 'noclickemail.com',
        'nogmailspam.info', 'nomail.pw', 'nomail2me.com', 'nomorespamemails.com', 'nospam.wins.com.br', 'nospam4.us',
        'nospamfor.us', 'nospammail.net', 'nowhere.org', 'nowmymail.com', 'nurfuerspam.de', 'objectmail.com',
        'obobbo.com', 'odaymail.com', 'onewaymail.com', 'ordinaryamerican.net', 'otherinbox.com', 'owlpic.com',
        'pancakemail.com', 'pimpedupmyspace.com', 'pjjkp.com', 'plexolan.de', 'politikerclub.de', 'poofy.org',
        'pookmail.com', 'proxymail.eu', 'prtnx.com', 'punkass.com', 'putthisinyourspamdatabase.com', 'quickinbox.com',
        'regbypass.com', 'rejectmail.com', 'rklips.com', 'rmqkr.net', 'rppkn.com', 'rtrtr.com',
        's0ny.net', 'safersignup.de', 'safetypost.de', 'sandelf.de', 'saynotospams.com', 'schafmail.de',
        'schrott-email.de', 'secretemail.de', 'secure-mail.biz', 'selfdestructingmail.com', 'sendspamhere.com', 'senseless-entertainment.com',
        'services391.com', 'sharedmailbox.org', 'shieldedmail.com', 'shiftmail.com', 'shitmail.me', 'shitware.nl',
        'shortmail.net', 'sinnlos-mail.de', 'skeefmail.com', 'slapsfromlastnight.com', 'slaskpost.se', 'slopsbox.com',
        'smashmail.de', 'smellfear.com', 'snakemail.com', 'sneakemail.com', 'snkmail.com', 'sofimail.com',
        'sofort-mail.de', 'soodonims.com', 'spoofmail.de', 'squizzy.de', 'ssoia.com', 'startkeys.com',
        'stinkefinger.net', 'stop-my-spam.cf', 'stuffmail.de', 'supergreatmail.com', 'supermailer.jp', 'superstachel.de',
        'sweetxxx.de', 'tagyourself.com', 'talkinator.com', 'tapchicuoihoi.com', 'teewars.org', 'teleosaurs.xyz',
        'temp.emeraldwebmail.com', 'temp.headstrong.de', 'tempalias.com', 'tempe-mail.com', 'tempemail.biz', 'tempemail.co.za',
        'tempinbox.co.uk', 'tempmail.eu', 'tempmail2.com', 'tempmaildemo.com', 'tempsky.com', 'tempthe.net',
        'thanksnospam.info', 'thankyou2010.com', 'thelimestones.com', 'thismail.net', 'throam.com', 'tilien.com',
        'tittbit.in', 'tizi.com', 'tmailinator.com', 'toiea.com', 'trash2009.com', 'trashdevil.com',
        'trashdevil.de', 'trashemail.de', 'trashmail.fr', 'trashmailer.com', 'trayna.com', 'trialmail.de',
        'trillianpro.com', 'twinmail.de', 'tyldd.com', 'uggsrock.com', 'upliftnow.com', 'uplipht.com',
        'uroid.com', 'venompen.com', 'viditag.com', 'viralplays.com', 'vsimcard.com', 'vubby.com',
        'wasteland.rfc822.org', 'webemail.me', 'weg-werf-email.de', 'wegwerf-emails.de', 'wegwerfadresse.de', 'wegwerfemail.com',
        'wegwerfemail.de', 'wegwerfmail.de', 'wegwerfmail.info', 'wegwerfmail.net', 'wegwerfmail.org', 'wh4f.org',
        'whatiaas.com', 'whatpaas.com', 'whatsaas.com', 'whopy.com', 'willhackforfood.biz', 'willselfdestruct.com',
        'winemaven.info', 'wronghead.com', 'wuzup.net', 'wuzupmail.net', 'wwwnew.eu', 'xagloo.com',
        'xemaps.com', 'xents.com', 'xmaily.com', 'xoxy.net', 'yapped.net', 'yogamaven.com',
        'yuurok.com', 'zehnminutenmail.de', 'zoaxe.com', 'zoemail.org', 'inboxbear.com', 'inboxkitten.com',
        'moakt.com', 'moakt.cc', 'moakt.ws', 'tmail.ws', 'tmails.net', 'tmailor.com',
        'tmpeml.com', 'tmpbox.net', 'tmpnator.live', 'emltmp.com', 'eyepaste.com', '1ce.us',
        'ephemail.net', 'ethereal.email', 'owlymail.com', 'linshiyouxiang.net', 'luxusmail.org', 'lyricspad.net',
        'fexbox.org', 'fexbox.ru', 'fexpost.com', 'fextemp.com', 'mailbox92.biz', 'crazymailing.com',
        'deadaddress.com', 'despam.it', 'despammed.com', 'dfgh.net', 'digitalsanctuary.com', 'gmailnator.com',
        'emailnator.com', 'smailpro.com', 'spamok.com', 'spamok.de', 'spamok.fr', 'anonmails.de',
        'anonymail.dk', 'anonymized.org', 'antispam24.de', 'bio-muesli.net', 'bugmenot.com', 'bumpymail.com',
        'dcemail.com', 'doiea.com', 'dodgit.com', 'dodgit.org', 'dontreg.com', 'dotmsg.com',
        'drdrb.com', 'drdrb.net', 'dump-email.info', 'dumpandjunk.com', 'dumpmail.de', 'dumpyemail.com',
        'e4ward.com', 'easytrashmail.com', 'einmalmail.de', 'einrot.de', 'eintagsmail.de', 'vomoto.com',
        'wegwerfemail.net', 'ensproxy.com',
    ];

    /** Cache key of the downloaded community list (php artisan mail:update-disposable-domains). */
    public const REMOTE_CACHE_KEY = 'disposable_email:remote_set';

    /** @var array<string,true>|null */
    private static ?array $remoteSet = null;

    /** @var array<string,true>|null */
    private static ?array $blockedSet = null;

    /** @var array<string,true>|null  bundled list: resources/data/disposable_email_domains.txt (~9.9k domains) */
    private static ?array $fileSet = null;

    private static function fileSet(): array
    {
        if (self::$fileSet === null) {
            self::$fileSet = [];
            $path = function_exists('resource_path') ? resource_path('data/disposable_email_domains.txt') : '';
            if ($path !== '' && is_file($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $d) {
                    $d = mb_strtolower(trim($d));
                    if ($d !== '' && $d[0] !== '#') {
                        self::$fileSet[$d] = true;
                    }
                }
            }
        }
        return self::$fileSet;
    }

    public static function enabled(): bool
    {
        return (bool) config('mail.block_disposable', true);
    }

    /** Lower-cased domain part of an address ('' when there is none). */
    public static function domainOf($email): string
    {
        $email = mb_strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        return $at === false ? '' : rtrim(substr($email, $at + 1), '.');
    }

    private static function csv($value): array
    {
        return array_values(array_filter(array_map(fn ($d) => mb_strtolower(trim($d)), explode(',', (string) $value))));
    }

    /** True when the domain, or one of its parent domains, is in the set. */
    private static function inSet(string $domain, array $set): bool
    {
        $parts = explode('.', $domain);
        for ($i = 0, $n = count($parts); $i < $n - 1; $i++) {
            if (in_array(implode('.', array_slice($parts, $i)), $set, true)) {
                return true;
            }
        }
        return false;
    }

    public static function isDisposableDomain(string $domain): bool
    {
        $domain = mb_strtolower(trim($domain));
        if ($domain === '' || !PlatformPolicy::flag('disposable_check')) {
            return false;
        }
        if (self::inSet($domain, self::csv(config('mail.allowed_domains', '')))) {
            return false;
        }
        self::$blockedSet ??= array_fill_keys(self::BLOCKED, true);
        self::$remoteSet ??= (array) Cache::get(self::REMOTE_CACHE_KEY, []);
        $extra = self::csv(config('mail.blocked_domains', ''));
        $parts = explode('.', $domain);
        for ($i = 0, $n = count($parts); $i < $n - 1; $i++) {
            $candidate = implode('.', array_slice($parts, $i));
            if (isset(self::$blockedSet[$candidate]) || isset(self::fileSet()[$candidate]) || isset(self::$remoteSet[$candidate]) || in_array($candidate, $extra, true)) {
                return true;
            }
        }
        return false;
    }

    public static function isDisposable($email): bool
    {
        if (!self::enabled() || PlatformPolicy::emailAllowed($email)) {
            return false;
        }

        return PlatformPolicy::emailBlockedBy($email) !== null || self::isDisposableDomain(self::domainOf($email));
    }

    /**
     * Throw-away tricks on otherwise legitimate domains (gmail, outlook, ...), which no domain
     * blocklist can catch:
     *  - plus-addressing on ANY domain (name+tag@outlook.com): unlimited aliases of one inbox;
     *  - Gmail/Googlemail "temp mail" addresses padded with dots / digit groups, e.g.
     *    frederickfie.lds.99.2.53@gmail.com or cuynhsoets.o.et.1245@gmail.com (Gmail ignores dots,
     *    so generators sprinkle them into one real inbox to mint endless "different" addresses):
     *      4+ dots, 2+ all-digit groups, or 3+ dots with 2+ tiny (1-2 letter) fragments.
     *    A normal four-part name (mohamed.ahmed.ali.hassan) or name.initial.year still passes.
     */
    public static function isSuspiciousMailbox($email): bool
    {
        $email = mb_strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $local = substr($email, 0, $at);
        if (PlatformPolicy::flag('plus_addressing_check') && str_contains($local, '+')) {
            return true;
        }
        if (PlatformPolicy::flag('suspicious_gmail_check')
            && in_array(self::domainOf($email), ['gmail.com', 'googlemail.com'], true)) {
            $groups = explode('.', $local);
            $dots = count($groups) - 1;
            $numeric = count(array_filter($groups, fn ($g) => $g !== '' && ctype_digit($g)));
            // tiny fragments: 1-2 characters that are not digits (the "o" and "et" in a.o.et.1245)
            $tiny = count(array_filter($groups, fn ($g) => $g !== '' && mb_strlen($g) <= 2 && !ctype_digit($g)));
            // empty groups = leading / trailing / doubled dots: never a real Gmail address
            $empty = count(array_filter($groups, fn ($g) => $g === ''));
            return $empty > 0 || $dots >= 4 || $numeric >= 2 || ($dots >= 3 && $tiny >= 2);
        }
        return false;
    }

    /** Can the domain receive mail? (MX, or A/AAAA as the implicit-MX fallback). Cached. */
    public static function domainCanReceiveMail(string $domain): bool
    {
        if (!function_exists('checkdnsrr')) {
            return true;
        }
        $key = 'real_email:dns:' . $domain;
        $cached = Cache::get($key);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $host = $domain . '.'; // absolute name: no search-domain surprises
        $ok = @checkdnsrr($host, 'MX') || @checkdnsrr($host, 'A') || @checkdnsrr($host, 'AAAA');
        if (!$ok && !(@checkdnsrr('google.com.', 'A') || @checkdnsrr('cloudflare.com.', 'A'))) {
            return true; // DNS itself is down: do not punish the user, and do not cache
        }

        Cache::put($key, $ok, $ok ? 86400 : 600);
        return $ok;
    }

    /**
     * Full verdict for one address, in the order the checks run:
     * allow list -> custom block list -> built-in disposable domains -> "+tag"/dotted-Gmail pattern -> DNS.
     *
     * @return array{ok:bool,code:?string,rule:?string,message:?string}
     *         code: null | custom_block | disposable | pattern | dns | allow_list (ok=true, address explicitly allowed)
     */
    public static function diagnose($email, string $locale = 'en', bool $checkDns = true, bool $skipPattern = false): array
    {
        $ok = ['ok' => true, 'code' => null, 'rule' => null, 'message' => null];
        if (!self::enabled()) {
            return $ok;
        }
        $ar = $locale === 'ar';
        $domain = self::domainOf($email);
        if ($domain === '') {
            return $ok; // format errors are reported by the normal "valid email" rule
        }
        if (PlatformPolicy::emailAllowed($email)) {
            return ['ok' => true, 'code' => 'allow_list'] + $ok;
        }
        if ($rule = PlatformPolicy::emailBlockedBy($email)) {
            return ['ok' => false, 'code' => 'custom_block', 'rule' => $rule['value'], 'message' => $ar
                ? 'هذا البريد الإلكتروني غير مسموح به في المنصة. استخدم بريدًا آخر.'
                : 'This email address is not allowed on this platform. Please use a different one.'];
        }
        if (self::isDisposableDomain($domain)) {
            return ['ok' => false, 'code' => 'disposable', 'rule' => $domain, 'message' => $ar
                ? 'لا يُسمح بالبريد الإلكتروني المؤقت. استخدم بريدًا إلكترونيًا حقيقيًا.'
                : 'Temporary / disposable email addresses are not allowed. Please use a real email address.'];
        }
        if (!$skipPattern && self::isSuspiciousMailbox($email) && !self::inSet($domain, self::csv(config('mail.allowed_domains', '')))) {
            return ['ok' => false, 'code' => 'pattern', 'rule' => null, 'message' => $ar
                ? 'هذا البريد الإلكتروني يبدو مؤقتًا أو مستعارًا (عنوان فيه + أو شكل عشوائي). استخدم بريدك الحقيقي الأساسي.'
                : 'This looks like a temporary or alias address (contains "+" or a random pattern). Please use your real, primary email address.'];
        }
        if ($checkDns && PlatformPolicy::flag('email_dns_check') && !self::domainCanReceiveMail($domain)) {
            return ['ok' => false, 'code' => 'dns', 'rule' => $domain, 'message' => $ar
                ? 'نطاق هذا البريد الإلكتروني غير صالح أو لا يستقبل رسائل. استخدم بريدًا إلكترونيًا حقيقيًا.'
                : 'This email domain cannot receive mail. Please use a real email address.'];
        }

        return $ok;
    }

    /**
     * @param bool $skipPattern skip only the "looks generated" pattern check (+tag / dot padding) for an address
     *                          whose inbox ownership is already proven (Google sign-in); domain blocklist still applies.
     * @return string|null a user-facing message when the address must be refused, null when it is fine.
     */
    public static function problem($email, string $locale = 'en', bool $checkDns = true, bool $skipPattern = false): ?string
    {
        return self::diagnose($email, $locale, $checkDns, $skipPattern)['message'];
    }
}
