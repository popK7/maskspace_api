<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#17122f">
    <title>Télécharger MakSpace</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f4fa;
            color: #201b36;
            font-synthesis: none;
            text-rendering: optimizeLegibility;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 32px 18px;
            background:
                radial-gradient(ellipse at 10% 12%, rgba(245, 139, 55, .14), transparent 34rem),
                radial-gradient(ellipse at 90% 85%, rgba(112, 82, 205, .14), transparent 34rem),
                #f5f4fa;
        }

        main { width: min(100%, 900px); }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 24px;
            color: #fff;
            font-size: 15px;
            font-weight: 750;
            letter-spacing: .02em;
        }

        .brand-mark {
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border-radius: 13px;
            background: linear-gradient(145deg, #ffae57, #f07832);
            box-shadow: 0 7px 18px rgba(240, 120, 50, .26);
            color: #211a35;
            font-size: 21px;
            font-weight: 900;
        }

        .hero {
            overflow: hidden;
            position: relative;
            padding: clamp(28px, 6vw, 56px);
            border-radius: 30px;
            background: linear-gradient(125deg, #211a3d, #17132d 68%, #30224a);
            box-shadow: 0 28px 80px rgba(35, 25, 68, .18);
            color: #fff;
        }

        .hero::after {
            position: absolute;
            top: -110px;
            right: -80px;
            width: 310px;
            height: 310px;
            border: 1px solid rgba(255, 255, 255, .09);
            border-radius: 50%;
            box-shadow: 0 0 0 35px rgba(255, 255, 255, .025), 0 0 0 75px rgba(255, 255, 255, .02);
            content: "";
            pointer-events: none;
        }

        .eyebrow {
            margin: 10px 0 12px;
            color: #ffc184;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .15em;
            text-transform: uppercase;
        }

        h1 {
            max-width: 620px;
            margin: 0;
            font-size: clamp(34px, 6vw, 54px);
            line-height: 1.04;
            letter-spacing: -.045em;
        }

        .intro {
            max-width: 550px;
            margin: 17px 0 31px;
            color: #d3cee4;
            font-size: 16px;
            line-height: 1.7;
        }

        .platforms {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .platform {
            display: flex;
            min-height: 210px;
            flex-direction: column;
            align-items: flex-start;
            padding: 23px;
            border: 1px solid rgba(255, 255, 255, .13);
            border-radius: 20px;
            background: rgba(255, 255, 255, .07);
            backdrop-filter: blur(8px);
        }

        .platform-icon {
            display: grid;
            width: 40px;
            height: 40px;
            place-items: center;
            margin-bottom: 16px;
            border-radius: 13px;
            background: rgba(255, 255, 255, .12);
            font-size: 21px;
        }

        h2 { margin: 0; font-size: 19px; }

        .platform p {
            min-height: 42px;
            margin: 8px 0 18px;
            color: #d3cee4;
            font-size: 13px;
            line-height: 1.55;
        }

        .button {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            margin-top: auto;
            padding: 0 17px;
            border-radius: 12px;
            background: #ffad58;
            color: #211a35;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            transition: transform .18s ease, background .18s ease;
        }

        .button:hover { transform: translateY(-2px); background: #ffc184; }
        .button:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }

        .button.disabled {
            background: rgba(255, 255, 255, .12);
            color: #c9c4d8;
            cursor: not-allowed;
        }

        .note {
            position: relative;
            z-index: 1;
            margin: 22px 0 0;
            color: #aaa4bf;
            font-size: 12px;
            line-height: 1.6;
        }

        footer {
            padding: 18px 4px 0;
            color: #77718d;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 620px) {
            .platforms { grid-template-columns: 1fr; }
            .platform { min-height: 0; }
            .platform p { min-height: 0; }
            .hero { border-radius: 24px; }
        }
    </style>
</head>
<body>
<main>
    <section class="hero" aria-labelledby="page-title">
        <div class="brand"><span class="brand-mark" aria-hidden="true">M</span> MakSpace</div>
        <p class="eyebrow">Une seule app, toutes vos possibilités</p>
        <h1 id="page-title">MakSpace vous accompagne partout.</h1>
        <p class="intro">Téléchargez l’application officielle pour accéder à vos services, vos demandes et vos espaces MakSpace.</p>

        <div class="platforms">
            <section class="platform" aria-labelledby="android-title">
                <span class="platform-icon" aria-hidden="true">◈</span>
                <h2 id="android-title">Android</h2>
                <p>Installez la dernière version de MakSpace sur votre téléphone Android.</p>
                @if ($androidAvailable)
                    <a class="button" href="{{ url('/api/v1/downloads/android') }}">Télécharger l’APK</a>
                @else
                    <span class="button disabled" aria-disabled="true">Bientôt disponible</span>
                @endif
            </section>

            <section class="platform" aria-labelledby="ios-title">
                <span class="platform-icon" aria-hidden="true">●</span>
                <h2 id="ios-title">iPhone et iPad</h2>
                <p>
                    @if ($iosUsesTestFlight)
                        Rejoignez la version iOS de MakSpace avec Apple TestFlight.
                    @else
                        Téléchargez la version iOS signée de MakSpace.
                    @endif
                </p>
                @if ($iosAvailable)
                    <a class="button" href="{{ route('downloads.ios') }}">
                        {{ $iosUsesTestFlight ? 'Ouvrir TestFlight' : 'Télécharger la version iOS' }}
                    </a>
                @else
                    <span class="button disabled" aria-disabled="true">Bientôt disponible</span>
                @endif
            </section>
        </div>

        <p class="note">Sur iPhone ou iPad, l’installation nécessite TestFlight ou une version iOS distribuée et signée selon les règles d’Apple. Un fichier APK est réservé à Android.</p>
    </section>
    <footer>© {{ date('Y') }} MakSpace</footer>
</main>
</body>
</html>
