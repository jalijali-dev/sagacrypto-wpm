/**
 * SagaCrypto — live-ish price ticker.
 *
 * Originally this connected client-side to Binance's public WebSocket
 * stream. That was switched to polling our own crypto-ticker-data.php
 * endpoint after confirming binance.com (and its stream subdomain) is
 * blocked at the ISP level in Indonesia — a nationwide domain block that no
 * amount of port-fallback/reconnect logic could work around, since it's the
 * domain itself that's unreachable from Indonesian networks, not a specific
 * port or a flaky connection.
 *
 * crypto-ticker-data.php reuses the same server-side CoinGecko cache that
 * already powers the homepage/crypto.php market tables (see
 * cms-admin/includes/crypto-api.php), so this adds no extra external API
 * calls — just a lightweight same-origin JSON fetch on an interval.
 *
 * Renders into <div id="wpm-live-ticker" data-symbols="BTCUSDT,ETHUSDT,...">
 * built by wpm_live_ticker_markup() in includes/site-bootstrap.php. If that
 * element isn't on the page, this script quietly does nothing.
 */
(function () {
    var container = document.getElementById('wpm-live-ticker');
    if (!container || typeof fetch === 'undefined') {
        return;
    }

    var symbolsAttr = container.getAttribute('data-symbols') || '';
    var symbols = symbolsAttr.split(',').map(function (s) { return s.trim().toUpperCase(); }).filter(Boolean);
    if (symbols.length === 0) {
        return;
    }

    var lastPrice = {};
    // Data itself is only as fresh as the Crypto API's own cache_duration
    // (default 300s), so polling faster than that just re-reads our own
    // cache — 30s keeps the UI feeling alive without hammering anything.
    var POLL_INTERVAL_MS = 30000;
    var endpoint = 'crypto-ticker-data.php?symbols=' + encodeURIComponent(symbols.join(','));

    function fmtPrice(value) {
        var n = parseFloat(value);
        if (isNaN(n)) { return '—'; }
        if (n >= 100) { return n.toFixed(2); }
        if (n >= 1) { return n.toFixed(3); }
        return n.toFixed(6);
    }

    function updateChip(symbol, price, changePct) {
        // The marquee now renders each chip TWICE back-to-back (seamless
        // CSS loop trick — see wpm_live_ticker_markup()), so every symbol
        // has two DOM nodes that both need updating, not just the first.
        var chips = container.querySelectorAll('[data-ticker-symbol="' + symbol + '"]');
        if (!chips.length) { return; }

        var prev = lastPrice[symbol];
        var direction = null;
        if (price !== null && price !== undefined && prev !== undefined) {
            direction = price > prev ? 'up' : (price < prev ? 'down' : null);
        }

        chips.forEach(function (chip) {
            var priceEl = chip.querySelector('.live-ticker__price');
            var changeEl = chip.querySelector('.live-ticker__change');

            if (priceEl && price !== null && price !== undefined) {
                priceEl.textContent = '$' + fmtPrice(price);
                if (direction) {
                    chip.classList.remove('is-flash-up', 'is-flash-down');
                    // Force reflow so the animation restarts on repeated updates.
                    void chip.offsetWidth;
                    chip.classList.add(direction === 'up' ? 'is-flash-up' : 'is-flash-down');
                }
                chip.classList.remove('is-loading');
            }

            if (changeEl && changePct !== null && changePct !== undefined && !isNaN(changePct)) {
                changeEl.textContent = (changePct >= 0 ? '+' : '') + changePct.toFixed(2) + '%';
                changeEl.classList.toggle('up', changePct >= 0);
                changeEl.classList.toggle('down', changePct < 0);
            }
        });

        if (price !== null && price !== undefined) {
            lastPrice[symbol] = price;
        }
    }

    function poll() {
        fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) { throw new Error('bad status'); }
                return res.json();
            })
            .then(function (json) {
                if (!json || typeof json !== 'object' || !json.coins) { return; }
                container.classList.add('is-connected');
                Object.keys(json.coins).forEach(function (symbol) {
                    var coin = json.coins[symbol];
                    if (!coin) { return; }
                    updateChip(symbol, coin.price, coin.change_pct);
                });
            })
            .catch(function () {
                container.classList.remove('is-connected');
            });
    }

    poll();
    var intervalId = setInterval(poll, POLL_INTERVAL_MS);

    window.addEventListener('beforeunload', function () {
        clearInterval(intervalId);
    });
}());
