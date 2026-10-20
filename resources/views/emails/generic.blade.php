<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ $heading }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
        table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
        img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
        a { text-decoration:none; }
        @media only screen and (max-width:620px) {
            .container { width:100% !important; }
            .px { padding-left:22px !important; padding-right:22px !important; }
            .h1 { font-size:22px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#eef2f9; font-family:'Tajawal','Cairo','Segoe UI',Tahoma,Arial,sans-serif; -webkit-font-smoothing:antialiased;">

    {{-- نص المعاينة (بيظهر جنب العنوان في صندوق الوارد) --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all; font-size:1px; line-height:1px; color:#eef2f9;">
        {{ $preheader }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background-color:#eef2f9;">
        <tr>
            <td align="center" style="padding:36px 14px;">

                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0"
                       style="width:600px; max-width:600px; background:#ffffff; border-radius:22px; overflow:hidden; box-shadow:0 12px 40px rgba(30,41,90,0.14);">

                    {{-- شريط ألوان علوي --}}
                    <tr>
                        <td style="font-size:0; line-height:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="25%" height="6" bgcolor="#6366f1" style="height:6px; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="25%" height="6" bgcolor="#ec4899" style="height:6px; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="25%" height="6" bgcolor="#f59e0b" style="height:6px; font-size:0; line-height:0;">&nbsp;</td>
                                    <td width="25%" height="6" bgcolor="#10b981" style="height:6px; font-size:0; line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- الهيدر (Hero) --}}
                    <tr>
                        <td class="px" align="center" bgcolor="{{ $theme['from'] }}"
                            style="background-color:{{ $theme['from'] }}; background-image:linear-gradient(135deg, {{ $theme['from'] }} 0%, {{ $theme['to'] }} 100%); padding:30px 36px 38px;">

                            {{-- البراند --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 26px;">
                                <tr>
                                    @if ($logoUrl)
                                        <td style="vertical-align:middle;">
                                            <img src="{{ $logoUrl }}" alt="{{ $appName }}" height="34" style="display:block; height:34px;">
                                        </td>
                                    @else
                                        <td style="vertical-align:middle; padding:0 0 0 10px;">
                                            <table role="presentation" cellpadding="0" cellspacing="0">
                                                <tr>
                                                    <td width="38" height="38" align="center" bgcolor="#ffffff"
                                                        style="width:38px; height:38px; background:#ffffff; border-radius:11px; color:{{ $theme['from'] }}; font-size:19px; font-weight:800; line-height:38px;">
                                                        {{ $initial }}
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                        <td style="vertical-align:middle; color:#ffffff; font-size:20px; font-weight:800; letter-spacing:0.4px;">
                                            {{ $appName }}
                                        </td>
                                    @endif
                                </tr>
                            </table>

                            {{-- الأيقونة --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 18px;">
                                <tr>
                                    <td width="92" height="92" align="center" bgcolor="{{ $theme['ring'] }}"
                                        style="width:92px; height:92px; border-radius:50%; background:{{ $theme['ring'] }};">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td width="68" height="68" align="center" bgcolor="#ffffff"
                                                    style="width:68px; height:68px; border-radius:50%; background:#ffffff; font-size:32px; line-height:68px;">
                                                    {{ $theme['icon'] }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- بادج النوع --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 14px;">
                                <tr>
                                    <td bgcolor="{{ $theme['ring'] }}"
                                        style="background:{{ $theme['ring'] }}; border-radius:999px; padding:5px 16px; color:#ffffff; font-size:12.5px; font-weight:700; letter-spacing:0.3px;">
                                        {{ $theme['badge'] }}
                                    </td>
                                </tr>
                            </table>

                            <h1 class="h1" style="margin:0; font-size:26px; line-height:1.45; color:#ffffff; font-weight:800; text-align:center;">{{ $heading }}</h1>
                        </td>
                    </tr>

                    {{-- المحتوى --}}
                    <tr>
                        <td class="px" style="padding:34px 36px 8px; text-align:{{ $align }};">

                            @foreach ($lines as $line)
                                <p style="margin:0 0 15px; font-size:15.5px; line-height:1.9; color:#475569; text-align:{{ $align }};">{{ $line }}</p>
                            @endforeach

                            @if (!empty($meta))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                       style="margin:22px 0 8px; background:{{ $theme['soft'] }}; border:1px solid {{ $theme['softBorder'] }}; border-radius:16px;">
                                    <tr>
                                        <td style="padding:18px 20px 6px; text-align:{{ $align }};">
                                            <span style="font-size:15px; font-weight:800; color:{{ $theme['accent'] }};">{{ $theme['metaIcon'] }}&nbsp; {{ $theme['metaTitle'] }}</span>
                                        </td>
                                    </tr>
                                    @foreach ($meta as $row)
                                        <tr>
                                            @if (!empty($row['code']))
                                            {{-- رمز التحقق: كبير وواضح ومتحدّد بضغطة واحدة (نسخ سهل) --}}
                                            <td align="center" style="padding:8px 20px 6px; text-align:center;">
                                                <div style="font-size:13px; color:#64748b; font-weight:700; margin:0 0 10px; text-align:center;">{{ $row['label'] }}</div>
                                                @if (!empty($row['copyUrl']))
                                                <table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:0 auto 12px;">
                                                    <tr>
                                                        <td align="center" bgcolor="{{ $theme['accent'] }}" style="border-radius:12px; background-color:{{ $theme['accent'] }};">
                                                            <a href="{{ $row['copyUrl'] }}" target="_blank" style="display:inline-block; padding:13px 34px; font-size:15px; font-weight:800; color:#ffffff; text-decoration:none; border-radius:12px;">📋&nbsp; {{ $locale === 'ar' ? 'افتح لنسخ الرمز' : 'Open to copy code' }}</a>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <div style="font-size:12px; color:#94a3b8; margin:-4px 0 12px; text-align:center;">{{ $locale === 'ar' ? 'هيفتح صفحة صغيرة تنسخ الرمز لك تلقائيًا' : 'Opens a small page that copies the code for you' }}</div>
                                                @endif
                                                <div dir="ltr" style="background:#ffffff; border:2px dashed {{ $theme['accent'] }}; border-radius:16px; padding:18px 8px 18px 18px; font-size:38px; line-height:1.2; font-weight:800; letter-spacing:12px; color:#0f172a; font-family:Consolas,'SFMono-Regular','Courier New',monospace; text-align:center; -webkit-user-select:all; user-select:all;">{{ $row['value'] }}</div>
                                                <div style="font-size:12.5px; color:#64748b; margin:10px 0 0; text-align:center;">{{ $locale === 'ar' ? 'أو اضغط مطولًا على الرمز لتحديده ثم انسخه' : 'Or tap and hold the code to select it, then copy' }}</div>
                                            </td>
                                            @else
                                            <td style="padding:8px 20px 6px; text-align:{{ $align }};">
                                                <div style="font-size:12.5px; color:#64748b; font-weight:500; margin:0 0 5px;">{{ $row['label'] }}</div>
                                                <div dir="ltr" style="background:#ffffff; border:1px solid {{ $theme['softBorder'] }}; border-radius:10px; padding:11px 14px; font-size:15px; font-weight:700; color:#0f172a; font-family:Consolas,'Courier New',monospace; text-align:left; word-break:break-all;">{{ $row['value'] }}</div>
                                            </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                    <tr><td style="height:12px; font-size:0; line-height:0;">&nbsp;</td></tr>
                                </table>
                            @endif

                            @if ($theme['note'])
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                       style="margin:14px 0 8px; background:#fffbeb; border:1px solid #fde68a; border-radius:14px;">
                                    <tr>
                                        <td style="padding:14px 18px; font-size:13.5px; line-height:1.8; color:#92400e; text-align:{{ $align }};">
                                            💡&nbsp; {{ $theme['note'] }}
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if ($button)
                                <table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin:28px auto 8px;">
                                    <tr>
                                        <td align="center" bgcolor="{{ $theme['accent'] }}"
                                            style="border-radius:14px; background-color:{{ $theme['accent'] }}; background-image:linear-gradient(135deg, {{ $theme['from'] }} 0%, {{ $theme['to'] }} 100%); box-shadow:0 8px 20px {{ $theme['shadow'] }};">
                                            <a href="{{ $button['url'] }}" target="_blank"
                                               style="display:inline-block; padding:16px 44px; font-size:16px; font-weight:800; color:#ffffff; text-decoration:none; border-radius:14px;">
                                                {{ $button['label'] }}&nbsp; {{ $t['arrow'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin:18px 0 6px; font-size:12px; line-height:1.8; color:#94a3b8; text-align:center; word-break:break-all;">
                                    {{ $t['copyLink'] }}<br>
                                    <a href="{{ $button['url'] }}" style="color:{{ $theme['accent'] }}; text-decoration:underline;">{{ $button['url'] }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>

                    <tr><td style="height:26px; font-size:0; line-height:0;">&nbsp;</td></tr>

                    {{-- الفوتر --}}
                    <tr>
                        <td class="px" align="center" bgcolor="#0f172a" style="background:#0f172a; padding:26px 36px 28px;">
                            <p style="margin:0 0 6px; font-size:15px; font-weight:800; color:#ffffff; letter-spacing:0.4px;">{{ $appName }}</p>
                            <p style="margin:0 0 6px; font-size:12.5px; line-height:1.8; color:#94a3b8;">{{ $t['auto'] }}</p>
                            <p style="margin:0; font-size:12px; color:#64748b;">&copy; {{ $year }} {{ $appName }}. {{ $t['rights'] }}</p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
