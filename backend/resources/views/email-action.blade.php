<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title>{{ $title }} · My vasesdhonneur</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: #0d5f57;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #17211f; }
    .card { width: 100%; max-width: 400px; background: #fff; border-radius: 22px; padding: 30px 26px; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.3); }
    .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 18px; font-weight: 700; color: #0d3f3a; }
    .brand img { width: 40px; height: 40px; border-radius: 50%; }
    .spinner { width: 30px; height: 30px; margin: 6px auto 14px; border: 3px solid #cfe3df; border-top-color: #0d5f57; border-radius: 50%; animation: s .8s linear infinite; }
    @keyframes s { to { transform: rotate(360deg); } }
    h1 { font-family: Georgia, serif; font-weight: normal; font-size: 23px; margin: 0 0 8px; color: #0d3f3a; }
    p { color: #44524f; line-height: 1.5; margin: 0; }
    button { margin-top: 16px; width: 100%; border: 0; border-radius: 999px; padding: 14px; font: inherit; font-weight: 700; background: #0d5f57; color: #fff; cursor: pointer; }
  </style>
</head>
<body>
  {{-- Page d'un instant : la decision part toute seule, puis l'application s'ouvre et la confirme. --}}
  <main class="card">
    <div class="brand"><img src="{{ $assetUrl }}/logo-vh.png" alt=""><span>My vasesdhonneur</span></div>
    <div class="spinner" aria-hidden="true"></div>
    <h1>{{ $title }}</h1>
    <p>L'application va s'ouvrir pour vous confirmer le résultat.</p>
    <form id="decision" method="post" action="{{ $action }}">
      <noscript><button type="submit">Confirmer</button></noscript>
    </form>
  </main>
  <script>
    (function () { var f = document.getElementById('decision'); if (!f.dataset.sent) { f.dataset.sent = '1'; f.submit(); } })();
  </script>
</body>
</html>
