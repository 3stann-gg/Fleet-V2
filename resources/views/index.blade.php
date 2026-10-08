<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>HIMS Fleet</title>
    <link rel="icon" href="./assets/images/brand/favicon.svg" type="image/svg+xml" />
    <script>
      (function () {
        var KEY = "himsFleetSession";
        function read(storage) {
          try {
            var raw = storage.getItem(KEY);
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            return parsed && parsed.authenticated === true ? parsed : null;
          } catch (e) {
            return null;
          }
        }
        var authed = Boolean(read(sessionStorage) || read(localStorage));
        window.location.replace(
          authed ? "./dashboard/index.html" : "./login/index.html",
        );
      })();
    </script>
  </head>
  <body>
    <p>
      Redirecting…
      <a href="./login/index.html">Sign in</a>
      ·
      <a href="./dashboard/index.html">Dashboard</a>
    </p>
  </body>
</html>
