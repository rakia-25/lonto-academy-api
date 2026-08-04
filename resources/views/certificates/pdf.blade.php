@php
    $design = $design ?? \App\Support\CertificateDesign::defaults($certificate->type ?? 'certificat');
    $type = ($certificate->type ?? 'certificat') === 'attestation' ? 'attestation' : 'certificat';
    $template = $design['template'] ?? 'classic';
    $accent = $design['accent_color'] ?? '#d4a017';
    $text = $design['text_color'] ?? '#0f1f3d';
    $brand = $design['brand_name'] ?: 'Lonto Academy';
    $title = $design['title'] ?: ($type === 'attestation' ? 'Attestation de réussite' : 'Certificat de réussite');
    $subtitle = $design['subtitle'] ?? '';
    $awarded = $design['awarded_label'] ?: 'Décerné à';
    $courseLabel = $design['course_label'] ?: 'Pour avoir terminé avec succès le cours';
    $footer = $design['footer'] ?? '';
    $showDate = !empty($design['show_date']);
    $showCode = !empty($design['show_verification_code']);
    $showSigner = !empty($design['show_signer']) && (trim($design['signer_name'] ?? '') !== '' || trim($design['signer_title'] ?? '') !== '');
    $logoPath = $logoPath ?? null;
    $verifyUrl = $verifyUrl ?? null;
    $showQr = !empty($showQr) && !empty($verifyUrl) && extension_loaded('gd');
    // DomPDF nécessite l'extension GD pour les PNG (logo). Sans GD, on ignore le logo.
    if ($logoPath && ! extension_loaded('gd')) {
        $logoPath = null;
    }
    $qrSrc = $showQr
        ? 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=6&data='.urlencode($verifyUrl)
        : null;
    $issuedLabel = $issuedLabel
        ?? \App\Support\CertificatePdf::formatIssuedAt($certificate->issued_at ?? now());
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $course->title }}</title>
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: {{ $text }};
            background: #fff;
        }
        .page {
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            position: relative;
        }
        .label {
            font-size: 11px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 6px;
        }
        .learner {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 22px;
            color: {{ $text }};
        }
        .course {
            font-size: 18px;
            font-weight: bold;
            color: {{ $text }};
            margin-bottom: 28px;
        }
        .meta {
            font-size: 11px;
            color: #64748b;
            line-height: 1.7;
        }
        .brand {
            font-size: 13px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: {{ $accent }};
            font-weight: bold;
            margin-bottom: 8px;
        }
        .title {
            font-size: 36px;
            font-weight: bold;
            margin: 0 0 8px;
            color: {{ $text }};
        }
        .subtitle {
            font-size: 13px;
            color: #64748b;
            margin: 0 0 28px;
        }
        .footer {
            font-size: 10px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            margin-top: 24px;
        }
        .signer {
            margin-top: 28px;
            font-size: 12px;
            color: #475569;
        }
        .signer-name {
            font-weight: bold;
            color: {{ $text }};
            font-size: 13px;
        }
        .signer-line {
            width: 160px;
            border-top: 1px solid #cbd5e1;
            margin-bottom: 6px;
        }
        .logo {
            max-height: 54px;
            max-width: 160px;
            margin-bottom: 10px;
        }
        .qr {
            width: 72px;
            height: 72px;
        }
        .qr-wrap {
            position: absolute;
            right: 48px;
            bottom: 36px;
            text-align: center;
        }
        .qr-caption {
            font-size: 8px;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* classic */
        .tpl-classic { padding: 34px; }
        .tpl-classic .inner {
            border: 3px solid {{ $accent }};
            height: 100%;
            box-sizing: border-box;
            padding: 38px 48px;
            position: relative;
        }
        .tpl-classic .accent {
            height: 6px;
            background: {{ $accent }};
            margin-bottom: 24px;
        }

        /* elegant */
        .tpl-elegant { padding: 28px; }
        .tpl-elegant .outer {
            border: 2px solid {{ $accent }};
            height: 100%;
            box-sizing: border-box;
            padding: 10px;
        }
        .tpl-elegant .inner {
            border: 1px solid {{ $text }};
            height: 100%;
            box-sizing: border-box;
            padding: 36px 48px;
            text-align: center;
        }
        .tpl-elegant .learner,
        .tpl-elegant .course { text-align: center; }
        .tpl-elegant .title { font-size: 34px; }
        .tpl-elegant .ornament {
            color: {{ $accent }};
            font-size: 18px;
            letter-spacing: 8px;
            margin-bottom: 14px;
        }

        /* modern */
        .tpl-modern { display: table; width: 100%; height: 100%; }
        .tpl-modern .side {
            display: table-cell;
            width: 42px;
            background: {{ $accent }};
            vertical-align: top;
        }
        .tpl-modern .inner {
            display: table-cell;
            vertical-align: middle;
            padding: 42px 48px;
        }
        .tpl-modern .title { font-size: 34px; }

        /* seal */
        .tpl-seal { padding: 34px; }
        .tpl-seal .inner {
            border: 1px solid #dbe3ef;
            height: 100%;
            box-sizing: border-box;
            padding: 36px 48px 36px 48px;
            position: relative;
            background: #fffdf8;
        }
        .tpl-seal .topbar {
            height: 4px;
            background: {{ $accent }};
            margin: -36px -48px 28px -48px;
        }
        .tpl-seal .seal {
            position: absolute;
            right: 42px;
            bottom: 42px;
            width: 90px;
            height: 90px;
            border: 3px solid {{ $accent }};
            border-radius: 50%;
            text-align: center;
            box-sizing: border-box;
            padding-top: 22px;
            color: {{ $accent }};
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            line-height: 1.35;
        }
        .tpl-seal .seal-inner {
            font-size: 16px;
            margin-bottom: 2px;
        }
    </style>
</head>
<body>
@if($template === 'elegant')
<div class="page tpl-elegant">
    <div class="outer">
        <div class="inner">
            <div class="ornament">* * *</div>
            @if($logoPath)<img class="logo" src="{{ $logoPath }}" alt=""><br>@endif
            <div class="brand">{{ $brand }}</div>
            <h1 class="title">{{ $title }}</h1>
            @if($subtitle)<p class="subtitle">{{ $subtitle }}</p>@endif
            <div class="label">{{ $awarded }}</div>
            <div class="learner">{{ $learner->name }}</div>
            <div class="label">{{ $courseLabel }}</div>
            <div class="course">{{ $course->title }}</div>
            <div class="meta">
                @if($showDate)Délivré le {{ $issuedLabel }}<br>@endif
                @if($showCode)Code de vérification : {{ $certificate->verification_code }}@endif
            </div>
            @if($showSigner)
                <div class="signer" style="text-align:center;">
                    <div class="signer-line" style="margin: 18px auto 6px;"></div>
                    @if(!empty($design['signer_name']))<div class="signer-name">{{ $design['signer_name'] }}</div>@endif
                    @if(!empty($design['signer_title']))<div>{{ $design['signer_title'] }}</div>@endif
                </div>
            @endif
            @if($footer)<div class="footer" style="text-align:center; margin-right: {{ $showQr ? '90px' : '0' }};">{{ $footer }}</div>@endif
            @if($qrSrc)
                <div class="qr-wrap" style="position:relative;right:auto;bottom:auto;margin:16px auto 0;">
                    <img class="qr" src="{{ $qrSrc }}" alt="QR">
                    <div class="qr-caption">Verification</div>
                </div>
            @endif
        </div>
    </div>
</div>
@elseif($template === 'modern')
<div class="page tpl-modern">
    <div class="side"></div>
    <div class="inner">
        @if($logoPath)<img class="logo" src="{{ $logoPath }}" alt=""><br>@endif
        <div class="brand">{{ $brand }}</div>
        <h1 class="title">{{ $title }}</h1>
        @if($subtitle)<p class="subtitle">{{ $subtitle }}</p>@endif
        <div class="label">{{ $awarded }}</div>
        <div class="learner">{{ $learner->name }}</div>
        <div class="label">{{ $courseLabel }}</div>
        <div class="course">{{ $course->title }}</div>
        <div class="meta">
            @if($showDate)Délivré le {{ $issuedLabel }}<br>@endif
            @if($showCode)Code de vérification : {{ $certificate->verification_code }}@endif
        </div>
        @if($showSigner)
            <div class="signer">
                <div class="signer-line"></div>
                @if(!empty($design['signer_name']))<div class="signer-name">{{ $design['signer_name'] }}</div>@endif
                @if(!empty($design['signer_title']))<div>{{ $design['signer_title'] }}</div>@endif
            </div>
        @endif
        @if($footer)<div class="footer" style="margin-right: {{ $showQr ? '90px' : '0' }};">{{ $footer }}</div>@endif
        @if($qrSrc)
            <div class="qr-wrap">
                <img class="qr" src="{{ $qrSrc }}" alt="QR">
                <div class="qr-caption">Verification</div>
            </div>
        @endif
    </div>
</div>
@elseif($template === 'seal')
<div class="page tpl-seal">
    <div class="inner">
        <div class="topbar"></div>
        @if($logoPath)<img class="logo" src="{{ $logoPath }}" alt=""><br>@endif
        <div class="brand">{{ $brand }}</div>
        <h1 class="title">{{ $title }}</h1>
        @if($subtitle)<p class="subtitle">{{ $subtitle }}</p>@endif
        <div class="label">{{ $awarded }}</div>
        <div class="learner">{{ $learner->name }}</div>
        <div class="label">{{ $courseLabel }}</div>
        <div class="course">{{ $course->title }}</div>
        <div class="meta">
            @if($showDate)Délivré le {{ $issuedLabel }}<br>@endif
            @if($showCode)Code de vérification : {{ $certificate->verification_code }}@endif
        </div>
        @if($showSigner)
            <div class="signer">
                <div class="signer-line"></div>
                @if(!empty($design['signer_name']))<div class="signer-name">{{ $design['signer_name'] }}</div>@endif
                @if(!empty($design['signer_title']))<div>{{ $design['signer_title'] }}</div>@endif
            </div>
        @endif
        @if($footer)<div class="footer" style="margin-right: 120px;">{{ $footer }}</div>@endif
        <div class="seal">
            <div class="seal-inner">*</div>
            {{ $type === 'attestation' ? 'Atteste' : 'Certifie' }}
        </div>
        @if($qrSrc)
            <div class="qr-wrap" style="right: 150px;">
                <img class="qr" src="{{ $qrSrc }}" alt="QR">
                <div class="qr-caption">Verification</div>
            </div>
        @endif
    </div>
</div>
@else
<div class="page tpl-classic">
    <div class="inner">
        <div class="accent"></div>
        @if($logoPath)<img class="logo" src="{{ $logoPath }}" alt=""><br>@endif
        <div class="brand">{{ $brand }}</div>
        <h1 class="title">{{ $title }}</h1>
        @if($subtitle)<p class="subtitle">{{ $subtitle }}</p>@endif
        <div class="label">{{ $awarded }}</div>
        <div class="learner">{{ $learner->name }}</div>
        <div class="label">{{ $courseLabel }}</div>
        <div class="course">{{ $course->title }}</div>
        <div class="meta">
            @if($showDate)Délivré le {{ $issuedLabel }}<br>@endif
            @if($showCode)Code de vérification : {{ $certificate->verification_code }}@endif
        </div>
        @if($showSigner)
            <div class="signer">
                <div class="signer-line"></div>
                @if(!empty($design['signer_name']))<div class="signer-name">{{ $design['signer_name'] }}</div>@endif
                @if(!empty($design['signer_title']))<div>{{ $design['signer_title'] }}</div>@endif
            </div>
        @endif
        @if($footer)<div class="footer" style="margin-right: {{ $showQr ? '90px' : '0' }};">{{ $footer }}</div>@endif
        @if($qrSrc)
            <div class="qr-wrap">
                <img class="qr" src="{{ $qrSrc }}" alt="QR">
                <div class="qr-caption">Verification</div>
            </div>
        @endif
    </div>
</div>
@endif
</body>
</html>
