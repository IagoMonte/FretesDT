@php
    $disp = "Montserrat, 'Segoe UI', Arial, sans-serif";
    $corpo = "'IBM Plex Sans', 'Segoe UI', Arial, sans-serif";
    $mono = "'IBM Plex Mono', 'Courier New', monospace";

    $palavras = explode(' ', mb_strtoupper($titulo));
    $ultima = array_pop($palavras);
    $inicio = implode(' ', $palavras);

    $rota = null;
    $valorFrete = null;
    $nota = null;
    $dados = [];
    foreach ($linhas as $rotulo => $valor) {
        if ($rotulo === 'Rota' && str_contains($valor, '→')) {
            $rota = array_map('trim', explode('→', $valor, 2));
        } elseif ($rotulo === 'Valor (R$/t)') {
            $valorFrete = 'R$ '.$valor;
        } elseif (in_array($rotulo, ['Motivo', 'Avisos', 'Observação'], true)) {
            $nota = ['rotulo' => $rotulo, 'texto' => $valor, 'alerta' => $rotulo !== 'Observação'];
        } else {
            $dados[$rotulo] = $valor;
        }
    }
    $pares = array_chunk($dados, 2, true);

    $pillTexto = $linhas['Status'] ?? ($etiqueta ?? 'Notificação');
    $pillTipo = $pill ?? 'claro';
    $pill = match ($pillTipo) {
        'verde' => ['bg' => '#00693C', 'bd' => '#00693C', 'fg' => '#FFFFFF'],
        'vermelho' => ['bg' => '#D6001C', 'bd' => '#D6001C', 'fg' => '#FFFFFF'],
        default => ['bg' => '#FFFFFF', 'bd' => '#FFFFFF', 'fg' => '#33463C'],
    };
    $btnCor = ($btn ?? 'verde') === 'vermelho' ? ['bg' => '#D6001C', 'sombra' => '#A50014'] : ['bg' => '#00693C', 'sombra' => '#004D2C'];
    $larguraBotao = 60 + 11 * mb_strlen($botao ?? 'Abrir no sistema');
@endphp
<!doctype html>
<html lang="pt-BR" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
<title>{{ $titulo }}</title>
</head>
<body style="margin:0;padding:0;background:#F5F5F2;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $chamada ?? $titulo }} {{ $codigo }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#F5F5F2" style="background:#F5F5F2;">
<tr><td align="center" style="padding:28px 12px 32px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">

    <tr><td style="padding:0 4px 14px;">
        <table role="presentation" cellpadding="0" cellspacing="0" align="left"><tr><td style="font-family:{!! $disp !!};font-weight:800;font-size:34px;letter-spacing:1px;line-height:1;color:#D6001C;">FRETES</td></tr>
        <tr><td align="right" style="font-family:{!! $disp !!};font-weight:700;font-size:17px;letter-spacing:.5px;line-height:1.2;color:#00693C;">DT</td></tr></table>
    </td></tr>
    <tr><td style="padding:0 4px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td width="50%" height="3" bgcolor="#D6001C" style="background:#D6001C;font-size:0;line-height:0;">&nbsp;</td><td width="50%" height="3" bgcolor="#00693C" style="background:#00693C;font-size:0;line-height:0;">&nbsp;</td></tr></table>
        <div style="font-family:{!! $disp !!};font-weight:700;font-size:11px;letter-spacing:6px;text-transform:uppercase;color:#D6001C;padding:9px 0 16px;">GESTÃO <span style="color:#00693C;">DE FRETES</span></div>
    </td></tr>

    <tr><td bgcolor="#FFFFFF" style="background:#FFFFFF;border:1px solid #E4E4DF;border-radius:12px;overflow:hidden;box-shadow:0 10px 28px -14px rgba(35,48,42,.18);">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family:{!! $corpo !!};color:#23302A;">

            <tr><td style="padding:16px 22px 14px;">
                <div style="font-family:{!! $mono !!};font-size:10px;letter-spacing:2px;text-transform:uppercase;color:#6B7A72;">FretesDT // {{ $secao ?? 'Notificação' }}</div>
                <div style="font-family:{!! $disp !!};font-weight:800;font-size:23px;letter-spacing:.4px;line-height:1.2;color:#23302A;padding-top:4px;">{{ $inicio }} <span style="color:#D6001C;">{{ $ultima }}</span></div>
            </td></tr>

            <tr><td bgcolor="#00522B" style="background:#00522B;background-image:linear-gradient(120deg,#00522B,#003B1E);padding:12px 18px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                    <td style="font-family:{!! $mono !!};font-weight:600;font-size:13px;letter-spacing:.6px;color:#FF9AA4;white-space:nowrap;">{{ $codigo }}<span style="font-weight:400;font-size:11.5px;letter-spacing:0;color:#9CC7B1;">&nbsp;&nbsp;{{ $data ?? '' }}</span></td>
                    <td align="right"><span style="display:inline-block;background:{{ $pill['bg'] }};border:1px solid {{ $pill['bd'] }};color:{{ $pill['fg'] }};font-family:{!! $disp !!};font-weight:700;font-size:10.5px;letter-spacing:1px;text-transform:uppercase;padding:4px 11px;border-radius:20px;white-space:nowrap;">{!! $pillTipo === 'claro_ponto' ? '<span style="color:#00693C;">&#9679;</span>&nbsp; ' : '' !!}{{ $pillTexto }}</span></td>
                </tr></table>
            </td></tr>

            <tr><td style="padding:18px 20px 0;">
                <p style="margin:0;font-size:14.5px;line-height:1.5;color:#23302A;">Olá, <strong>{{ $nome }}</strong>.</p>
                <p style="margin:2px 0 0;font-size:13.5px;line-height:1.5;color:#33463C;">{{ $chamada ?? '' }}</p>
            </td></tr>

            @if ($rota)
            <tr><td style="padding:16px 20px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                    <td style="white-space:nowrap;font-family:{!! $disp !!};font-weight:700;font-size:12.5px;letter-spacing:.3px;text-transform:uppercase;color:#23302A;"><span style="color:#00693C;font-size:11px;">&#9679;</span>&nbsp; {{ $rota[0] }}</td>
                    <td width="100%" style="padding:0 10px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:2px dashed #CFD6D1;font-size:0;line-height:0;height:1px;">&nbsp;</td><td width="12" style="font-size:13px;line-height:1;color:#D6001C;">&#9656;</td></tr></table></td>
                    <td align="right" style="white-space:nowrap;font-family:{!! $disp !!};font-weight:700;font-size:12.5px;letter-spacing:.3px;text-transform:uppercase;color:#23302A;"><span style="color:#D6001C;font-size:11px;">&#9679;</span>&nbsp; {{ $rota[1] }}</td>
                </tr></table>
            </td></tr>
            @endif

            @if ($valorFrete)
            <tr><td style="padding:16px 20px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#E6F2EC;border:1px solid #BFDCCB;border-left:4px solid #00693C;border-radius:10px;">
                    <tr><td bgcolor="#E6F2EC" style="padding:12px 16px;">
                        <div style="font-family:{!! $mono !!};font-size:10px;letter-spacing:1.6px;text-transform:uppercase;color:#6B7A72;">Valor do frete</div>
                        <div style="font-family:{!! $disp !!};font-weight:800;font-size:25px;line-height:1.15;color:#004D2C;">{{ $valorFrete }} <span style="font-family:{!! $mono !!};font-weight:500;font-size:12px;color:#6B7A72;">/ tonelada</span></div>
                    </td></tr>
                </table>
            </td></tr>
            @endif

            @if ($pares)
            <tr><td style="padding:16px 20px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    @foreach ($pares as $par)
                    <tr>
                        @foreach ($par as $rotulo => $valor)
                        <td width="50%" valign="top" style="padding:0 {{ $loop->first ? '9px' : '0' }} 11px {{ $loop->first ? '0' : '9px' }};">
                            <div style="font-family:{!! $mono !!};font-size:10px;letter-spacing:1.4px;text-transform:uppercase;color:#6B7A72;">{{ $rotulo }}</div>
                            @if (preg_match('/^(COT|CRG)-/', $valor))
                            <div style="font-family:{!! $mono !!};font-weight:600;font-size:13.5px;color:#D6001C;padding-top:1px;">{{ $valor }}</div>
                            @else
                            <div style="font-weight:600;font-size:13.5px;color:#23302A;padding-top:1px;">{{ $valor }}</div>
                            @endif
                        </td>
                        @endforeach
                        @if (count($par) === 1)<td width="50%">&nbsp;</td>@endif
                    </tr>
                    @endforeach
                </table>
            </td></tr>
            @endif

            @if ($nota)
            @php $nc = $nota['alerta'] ? ['bg' => '#FBE7E9', 'bd' => '#F3B7BD', 'fg' => '#A50014', 'ic' => '!'] : ['bg' => '#EEF1F0', 'bd' => '#C9D2CD', 'fg' => '#33463C', 'ic' => 'i']; @endphp
            <tr><td style="padding:5px 20px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $nc['bg'] }};border:1px solid {{ $nc['bd'] }};border-radius:8px;">
                    <tr><td bgcolor="{{ $nc['bg'] }}" style="padding:10px 13px;font-size:13px;line-height:1.5;color:{{ $nc['fg'] }};"><span style="font-family:{!! $mono !!};font-weight:600;">{{ $nc['ic'] }}</span>&nbsp; <strong style="color:{{ $nota['alerta'] ? '#A50014' : '#23302A' }};">{{ $nota['rotulo'] }}</strong> — {{ $nota['texto'] }}</td></tr>
                </table>
            </td></tr>
            @endif

            <tr><td style="padding:14px 0 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#FCFCFA" style="background:#FCFCFA;border-top:1px solid #E4E4DF;">
                    <tr>
                        <td style="padding:14px 20px;font-size:11.5px;color:#6B7A72;">Mensagem automática</td>
                        <td align="right" style="padding:14px 20px 17px;">
                            <!--[if mso]>
                            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $link }}" arcsize="18%" fillcolor="{{ $btnCor['bg'] }}" strokecolor="{{ $btnCor['sombra'] }}" strokeweight="2pt" style="height:42px;width:{{ $larguraBotao }}px;v-text-anchor:middle;">
                                <w:anchorlock/>
                                <center style="color:#FFFFFF;font-family:Arial,sans-serif;font-weight:bold;font-size:13px;letter-spacing:1px;">{{ mb_strtoupper($botao ?? 'Abrir no sistema') }}</center>
                            </v:roundrect>
                            <![endif]-->
                            <!--[if !mso]><!-->
                            <a href="{{ $link }}" style="display:inline-block;background:{{ $btnCor['bg'] }};color:#FFFFFF;font-family:{!! $disp !!};font-weight:700;font-size:13px;letter-spacing:1.1px;text-transform:uppercase;text-decoration:none;padding:10px 18px;border-radius:8px;border-bottom:3px solid {{ $btnCor['sombra'] }};">{{ $botao ?? 'Abrir no sistema' }}</a>
                            <!--<![endif]-->
                        </td>
                    </tr>
                </table>
            </td></tr>

            <tr><td style="font-size:0;line-height:0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;"><tr>
                    @for ($i = 0; $i < 23; $i++)
                    <td height="5" bgcolor="{{ $i % 2 ? '#D6001C' : '#00693C' }}" style="background:{{ $i % 2 ? '#D6001C' : '#00693C' }};font-size:0;line-height:0;">&nbsp;</td>
                    @endfor
                </tr></table>
            </td></tr>
        </table>
    </td></tr>

    <tr><td align="center" style="padding:14px 0 0;font-family:{!! $mono !!};font-size:10px;letter-spacing:1.6px;line-height:1.7;text-transform:uppercase;color:#93A29A;">Não responda este e-mail &middot; responda no sistema<br>FretesDT &middot; Gestão de Fretes</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
