const DEBUG = true;

// API credentials are kept server-side in api.php.
// The browser only talks to our same-origin proxy.
const PROXY_URL = "azuracast-api-proxy.php";
const STATION_ID = "1";

function renderStationDashboard(data) {
  if (!data) return;

  document.getElementById("stationNameDisplay").textContent = data.name || "Stacja radiowa";
  document.getElementById("stShortcode").textContent = data.shortcode || "–";

  const badge = document.getElementById("stPublicBadge");
  if (data.is_public) {
    badge.textContent = "PUBLICZNA";
    badge.style.background = "#28a745";
    badge.style.color = "#fff";
  } else {
    badge.textContent = "PRYWATNA";
    badge.style.background = "#dc3545";
    badge.style.color = "#fff";
  }

  // 4. Dane techniczne (Frontend / Backend)
  const frontend = data.frontend || "nieznany";
  const backend = data.backend || "nieznany";
  document.getElementById("stTech").textContent = `${frontend} / ${backend}`;

  document.getElementById("stId").textContent = `${data.id}`;

  // 5. Linki i URL-e
  const setLink = (id, url, text) => {
    const el = document.getElementById(id);
    if (el && url) {
      el.href = url;
      if (text) el.textContent = text;
      el.style.display = "inline";
    } else if (el) {
      el.style.display = "none";
    }
  };

  setLink("stUrl", data.url, data.url);
  setLink("stListenUrl", data.listen_url);
  setLink("stHlsUrl", data.hls_url);
  setLink("stPls", data.playlist_pls_url);
  setLink("stM3u", data.playlist_m3u_url);
}

// ──────────────────────────────────────────────
// STATE
// ──────────────────────────────────────────────
let liveTimer = null;
let listenersTimer = null;
const charts = {};
const DAYS_PL = ["Poniedziałek", "Wtorek", "Środa", "Czwartek", "Piątek", "Sobota", "Niedziela"];
const HOURS = Array.from({ length: 24 }, (_, i) => i + "h");

function getSid() {
  return STATION_ID;
}

async function apiFetch(path) {
  const url = `${PROXY_URL}?path=${encodeURIComponent(path)}`;
  const r = await fetch(url, {
    method: "GET",
    credentials: "same-origin",
    cache: "no-store",
  });
  if (!r.ok) {
    let detail = "";
    try {
      const e = await r.json();
      detail = e.error ? `: ${e.error}` : "";
    } catch (_) {}
    throw new Error(`HTTP ${r.status}${detail} dla ${path}`);
  }
  return r.json();
}

// POST is intentionally disabled: this dashboard only needs read-only API access.
async function apiPost() {
  throw new Error("Operacje POST są wyłączone w tym dashboardzie.");
}

// ──────────────────────────────────────────────
// STATUS
// ──────────────────────────────────────────────
function showStatus(msg, isErr = false) {
  const bar = document.getElementById("statusBar");
  const sp = document.getElementById("spinner");
  bar.className = "status-bar visible" + (isErr ? " error" : "");
  document.getElementById("statusMsg").textContent = msg;
  sp.style.display = isErr ? "none" : "inline-block";
}
function hideStatus() {
  const bar = document.getElementById("statusBar");
  bar.className = "status-bar"; // usuwa "visible" i "error"
  document.getElementById("spinner").style.display = "none";
}

// ──────────────────────────────────────────────
// TABS
// ──────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll(".tab-panel").forEach((p) => p.classList.remove("active"));
  document.querySelectorAll(".tab").forEach((t) => t.classList.remove("active"));
  document.getElementById("tab-" + name).classList.add("active");
  event.target.classList.add("active");
}

// ──────────────────────────────────────────────
// CHART HELPERS
// ──────────────────────────────────────────────
const C = {
  amber: "#F4A261",
  live: "#4FC3F7",
  grid: "rgba(255,255,255,0.05)",
  text: "#7A8FA3",
  success: "#4ADE80",
  violet: "#A78BFA",
};

function baseOpts(extraX = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false }, // Wykres ma 1 serię, więc legendę główną ukrywamy
      tooltip: {
        enabled: true, // Upewniamy się, że tooltip jest włączony
        mode: "index",
        intersect: false,
        backgroundColor: "#1A2F45",
        borderColor: "rgba(255,255,255,0.1)",
        borderWidth: 1,
        titleColor: "#E8EDF2",
        bodyColor: "#7A8FA3",
        padding: 10,
        cornerRadius: 8,
        callbacks: {
          // Tytuł w okienku tooltipa (np. Data / Dzień)
          title: function (context) {
            return context[0].label || "";
          },
          // Treść w okienku tooltipa (np. Słuchacze: 125)
          label: function (context) {
            const value = context.raw || 0;
            return `Słuchacze: ${value.toLocaleString("pl-PL")}`;
          },
        },
      },
    },
    scales: {
      x: { grid: { color: C.grid, drawBorder: false }, ticks: { color: C.text, font: { size: 11 } }, ...extraX },
      y: { grid: { color: C.grid, drawBorder: false }, ticks: { color: C.text, font: { size: 11 } }, beginAtZero: true },
    },
  };
}

function baseOpts1(extraX = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: "#1A2F45",
        borderColor: "rgba(255,255,255,0.1)",
        borderWidth: 1,
        titleColor: "#E8EDF2",
        bodyColor: "#7A8FA3",
        padding: 10,
        cornerRadius: 8,
      },
    },
    scales: {
      x: { grid: { color: C.grid, drawBorder: false }, ticks: { color: C.text, font: { size: 11 } }, ...extraX },
      y: { grid: { color: C.grid, drawBorder: false }, ticks: { color: C.text, font: { size: 11 } }, beginAtZero: true },
    },
  };
}

function makeChart(id, type, labels, data, color, opts = {}) {
  if (charts[id]) {
    charts[id].destroy();
  }
  const ctx = document.getElementById(id);
  if (!ctx) return;
  charts[id] = new Chart(ctx, {
    type,
    data: {
      labels,
      datasets: [
        {
          label: "Słuchacze", // <--- Dodanie nazwy serii danych
          data,
          backgroundColor: type === "line" ? color + "22" : color + "cc",
          borderColor: color,
          borderWidth: type === "line" ? 2 : 0,
          fill: type === "line",
          tension: 0.35,
          borderRadius: type === "bar" ? 4 : 0,
          pointRadius: 0,
          hoverPointRadius: 5, // Rozmiar punktu po najechaniu myszką
          ...opts,
        },
      ],
    },
    options: baseOpts(),
  });
}

function makeChart1(id, type, labels, data, color, opts = {}) {
  if (charts[id]) {
    charts[id].destroy();
  }
  const ctx = document.getElementById(id);
  if (!ctx) return;
  charts[id] = new Chart(ctx, {
    type,
    data: {
      labels,
      datasets: [
        {
          data,
          backgroundColor: type === "line" ? color + "22" : color + "cc",
          borderColor: color,
          borderWidth: type === "line" ? 2 : 0,
          fill: type === "line",
          tension: 0.35,
          borderRadius: type === "bar" ? 4 : 0,
          pointRadius: 0,
          hoverPointRadius: 4,
          ...opts,
        },
      ],
    },
    options: baseOpts(),
  });
}

// ──────────────────────────────────────────────
// LOAD ALL
// ──────────────────────────────────────────────
async function loadAll() {
  // Reset UI — ukryj panele, pokaż komunikat ładowania
  showStatus("Łączenie z API …");
  document.getElementById("liveStrip").style.display = "none";
  document.getElementById("mainTabs").style.display = "none";
  document.getElementById("topbar").style.display = "none";
  document.getElementById("kpiRow").style.display = "none";
  document.getElementById("overviewCharts").style.display = "none";
  document.getElementById("overviewEmpty").style.display = "block";
  document.getElementById("bwGrid").style.display = "none";
  document.getElementById("mostPlayedCard").style.display = "none";
  document.getElementById("tracksEmpty").style.display = "block";
  document.getElementById("listenersGeo").style.display = "none";
  document.getElementById("listenersTableCard").style.display = "none";
  document.getElementById("listenersEmpty").style.display = "block";
  document.getElementById("requestsCard").style.display = "none";

  const errors = [];

  // Pomocnik — uruchamia sekcję, zbiera błędy bez przerywania pozostałych
  const run = async (label, fn) => {
    showStatus(label + "…");
    try {
      await fn();
    } catch (e) {
      errors.push(`${label}: ${e.message}`);
      if (DEBUG) console.warn(`[loadAll] ${label}:`, e);
    }
  };

  // 1. Dane live + odświeżanie co 10 s
  if (liveTimer) clearInterval(liveTimer);
  await run("Dane na żywo", async () => {
    await loadNowPlaying(); // pierwsze wywołanie — awaited
    liveTimer = setInterval(loadNowPlaying, 10000);
  });

  // 2. Dane historyczne i raporty (równolegle — szybsze ładowanie)
  await Promise.allSettled([
    run("Wykresy historyczne", loadCharts),
    run("Rankingi utworów", loadBestWorst),
    run("Historia odtworzeń", loadMostPlayed),
    run("Dane słuchaczy", loadListeners),
    // run("Zamówienia",       loadRequests),
  ]).then((results) => {
    results.forEach((r) => {
      if (r.status === "rejected") {
        errors.push(r.reason?.message || "Nieznany błąd");
        if (DEBUG) console.warn("[loadAll] Promise rejected:", r.reason);
      }
    });
  });

  // Pokaż UI po załadowaniu
  document.getElementById("mainTabs").style.display = "flex";
  document.getElementById("topbar").style.display = "flex";

  // Uruchom live-polling dla zakładki Połączenia (co 30 s)
  startListenersPolling();

  // Finalna informacja w pasku statusu
  if (errors.length) {
    showStatus("Załadowano z błędami (" + errors.length + "): " + errors.join(" · "), true);
  } else {
    hideStatus();
  }
}

// ──────────────────────────────────────────────
// NOW PLAYING
// ──────────────────────────────────────────────
async function loadNowPlaying() {
  try {
    const d = await apiFetch(`/station/${getSid()}/nowplaying`);
    document.getElementById("liveCount").textContent = d.listeners?.current ?? "–";
    document.getElementById("liveUnique").textContent = d.listeners?.unique ?? "–";
    document.getElementById("liveStatus").textContent = d.is_online ? "✔ Online" : "✖ Offline";
    const mb = d.station?.mounts?.[0];
    document.getElementById("liveBitrate").textContent = mb?.bitrate ? mb.bitrate + " kbps" : "–";
    const np = d.now_playing?.song;
    document.getElementById("liveTitle").textContent = np?.title || np?.text || "–";
    document.getElementById("liveArtist").textContent = np?.artist || "";
    document.getElementById("refreshInfo").textContent =
      "Odświeżono: " + new Date().toLocaleTimeString("pl-PL", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
    // Ujawnij pasek live dopiero gdy dane są gotowe
    document.getElementById("liveStrip").style.display = "flex";

    // Uzupełnij topbar danymi stacji (tylko raz — gdy element jeszcze pusty)
    if (d.station && document.getElementById("stId").textContent.trim() == "-") {
      renderStationDashboard(d.station);
    }

    // Song history
    renderSongHistory(d.song_history || [], d.now_playing || null);
  } catch (e) {
    // Nie nadpisuj globalnego paska — tylko loguj; loadAll zbiera błędy osobno
    if (DEBUG) console.warn("[loadNowPlaying]", e.message);
    throw e; // re-throw żeby run() mógł zebrać błąd
  }
}

// ──────────────────────────────────────────────
// SONG HISTORY — renderowana przy każdym nowplaying (co 10 s)
// ──────────────────────────────────────────────

/**
 * Formatuje czas trwania utworu z sekund na m:ss
 */
function _fmtTrackDuration(sec) {
  if (!sec || sec <= 0) return "–";
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return m + ":" + String(s).padStart(2, "0");
}

/**
 * Renderuje tabelę ostatnio granych z d.song_history[].
 *
 * @param {Array}       history    - d.song_history (może być [])
 * @param {Object|null} nowPlaying - d.now_playing (aktualnie grany, wyróżniony)
 */
function renderSongHistory(history, nowPlaying) {
  const card = document.getElementById("songHistoryCard");
  const tbody = document.getElementById("songHistoryBody");
  const sub = document.getElementById("songHistorySub");

  if (!Array.isArray(history) || history.length === 0) {
    card.style.display = "none";
    return;
  }

  const now = Math.floor(Date.now() / 1000);

  // Aktualnie grany utwór — dołącz na początku listy jako wiersz #0
  const rows = [];

  if (nowPlaying && nowPlaying.song) {
    rows.push({
      song: nowPlaying.song,
      played_at: nowPlaying.played_at || now,
      duration: nowPlaying.duration || 0,
      isLive: true,
    });
  }

  history.forEach(function (item) {
    rows.push({
      song: item.song || {},
      played_at: item.played_at || 0,
      duration: item.duration || item.song?.length || 0,
      isLive: false,
    });
  });

  tbody.innerHTML = "";

  rows.forEach(function (row, idx) {
    const song = row.song;
    const title = esc(song.title || song.text || "–");
    const artist = esc(song.artist || "");
    const album = esc(song.album || "");
    const dur = _fmtTrackDuration(row.duration);

    // Czas emisji — "teraz" dla aktualnego, godzina dla historycznych
    var playedAtStr;
    if (row.isLive) {
      playedAtStr = '<span style="color:var(--live);font-weight:600">▶ teraz</span>';
    } else if (row.played_at > 0) {
      playedAtStr = new Date(row.played_at * 1000).toLocaleTimeString("pl-PL", {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
      });
    } else {
      playedAtStr = "–";
    }

    const tr = document.createElement("tr");
    if (row.isLive) {
      tr.style.cssText = "background:rgba(var(--live-rgb,220,53,69),.06)";
    }

    tr.innerHTML =
      '<td class="num" style="width:28px;color:var(--text-muted);font-size:11px">' +
      (row.isLive ? "▶" : idx) +
      "</td>" +
      "<td><strong>" +
      title +
      "</strong></td>" +
      "<td>" +
      artist +
      "</td>" +
      '<td class="muted" style="font-size:12px">' +
      album +
      "</td>" +
      "<td>" +
      playedAtStr +
      "</td>" +
      '<td class="num muted" style="font-variant-numeric:tabular-nums;font-size:12px">' +
      dur +
      "</td>";

    tbody.appendChild(tr);
  });

  // Podtytuł — liczba wpisów + czas ostatniej aktualizacji
  sub.textContent =
    rows.length + " ostatnich utworów · odświeżone " + new Date().toLocaleTimeString("pl-PL", { hour: "2-digit", minute: "2-digit", second: "2-digit" });

  card.style.display = "block";
}

// ──────────────────────────────────────────────
// CHARTS
// ──────────────────────────────────────────────
async function loadCharts() {
  try {
    const d = await apiFetch(`/station/${getSid()}/reports/overview/charts`);

    // Extract arrays
    const dailyMetric = d?.daily?.metrics?.[0];
    const dailyAlt = d?.daily?.alt?.[0]?.values || [];

    const hourlyAll = d?.hourly?.all;
    const hourlyMetric = hourlyAll?.metrics?.[0];

    const dowLabels = d?.day_of_week?.labels || DAYS_PL;
    const dowMetric = d?.day_of_week?.metrics?.[0];

    // Parse daily
    const dlabels = dailyAlt.map((r) => r.label || "");
    const dvals = dailyMetric?.data ? dailyMetric.data.map((r) => Number(r.y ?? 0)) : [];

    // Parse hourly
    const hlabels = hourlyAll?.labels || [];
    const hvals = hourlyMetric?.data ? hourlyMetric.data.map((v) => Number(v ?? 0)) : [];

    // Parse dow
    const wlabels = dowLabels;
    const wvals = dowMetric?.data ? dowMetric.data.map((v) => Number(v ?? 0)) : [];

    // KPIs
    const total = dvals.reduce((a, b) => a + b, 0);

    const maxH = hvals.length ? Math.max(...hvals) : 0;
    const maxHIdx = hvals.indexOf(maxH);

    const maxW = wvals.length ? Math.max(...wvals) : 0;
    const maxWIdx = wvals.indexOf(maxW);

    const maxD = dvals.length ? Math.max(...dvals) : 0;
    const maxDIdx = dvals.indexOf(maxD);

    document.getElementById("kpiTotal").textContent = total.toLocaleString("pl-PL");
    document.getElementById("kpiPeak").textContent = maxH.toLocaleString("pl-PL");
    document.getElementById("kpiPeakHour").textContent = maxHIdx >= 0 ? `godzina ${maxHIdx}:00` : "–";
    document.getElementById("kpiBestDay").textContent = maxWIdx >= 0 ? DAYS_PL[maxWIdx] : "–";
    document.getElementById("kpiBestDaySub").textContent = maxW.toLocaleString("pl-PL") + " odsłon";
    document.getElementById("kpiBestDate").textContent = maxDIdx >= 0 ? dlabels[maxDIdx] || maxDIdx : "–";
    document.getElementById("kpiBestDateSub").textContent = maxD.toLocaleString("pl-PL") + " odsłon";

    document.getElementById("kpiRow").style.display = "grid";
    document.getElementById("overviewEmpty").style.display = "none";

    // Overview charts (smaller)
    makeChart("chartHourly", "bar", hlabels.length ? hlabels : HOURS, hvals, C.amber);
    makeChart("chartDow", "bar", wlabels, wvals, C.live);
    makeChart("chartDaily", "line", dlabels, dvals, C.amber);

    // Full-tab charts
    makeChart("chartDailyFull", "line", dlabels, dvals, C.amber);
    makeChart("chartHourlyFull", "bar", hlabels.length ? hlabels : HOURS, hvals, C.amber);
    makeChart("chartDowFull", "bar", wlabels, wvals, C.live);

    const rangeElement = document.getElementById("statsRange");
    const dailyValues = d?.daily?.alt?.[0]?.values;
    if (rangeElement && dailyValues && dailyValues.length > 0) {
      const startDate = dailyValues[0].label;
      const endDate = dailyValues[dailyValues.length - 1].label;
      rangeElement.textContent = `Zakres: ${startDate} – ${endDate}`;
    }
    document.getElementById("overviewCharts").style.display = "block";
    document.getElementById("overviewEmpty").style.display = "none";
  } catch (e) {
    if (DEBUG) console.warn("[loadCharts]", e.message);
    throw e;
  }
}

// ──────────────────────────────────────────────
// BEST / WORST
// ──────────────────────────────────────────────
async function loadBestWorst() {
  try {
    const d = await apiFetch(`/station/${getSid()}/reports/overview/best-and-worst`);

    const renderList = (el, items, sign, isMostPlayed = false) => {
      el.innerHTML = "";
      if (!items || !items.length) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:8px 0">Brak danych</div>';
        return;
      }

      items.slice(0, 8).forEach((it) => {
        const song = it.song || it;
        // Wyciąganie wartości delta (dla best/worst) lub liczby odtworzeń (dla mostPlayed)
        const val = isMostPlayed ? (it.num_plays ?? 0) : (it.stat_delta ?? it.listeners_change ?? it.delta ?? 0);

        const div = document.createElement("div");
        div.className = "bw-item";
        div.innerHTML = `
              <div class="bw-item-text">
                <div class="bw-item-title">${esc(song.title || song.text || "–")}</div>
                <div class="bw-item-artist">${esc(song.artist || "")}</div>
              </div>
              <div class="bw-item-delta">${sign}${Math.abs(val).toLocaleString("pl-PL")}</div>`;
        el.appendChild(div);
      });
    };

    renderList(document.getElementById("bestList"), d?.bestAndWorst?.best || [], "+");
    renderList(document.getElementById("worstList"), d?.bestAndWorst?.worst || [], "");

    document.getElementById("bwGrid").style.display = "grid";
    document.getElementById("tracksEmpty").style.display = "none";
  } catch (e) {
    if (DEBUG) console.warn("[loadBestWorst]", e.message);
    throw e;
  }
}

// ──────────────────────────────────────────────
// MOST PLAYED
// ──────────────────────────────────────────────
async function loadMostPlayed() {
  try {
    const d = await apiFetch(`/station/${getSid()}/history`);

    // 1. Wyciągnięcie surowej listy odtworzeń z obiektu
    const history = Array.isArray(d) ? d : d.song_history || d.tracks || d.songs || [];
    if (!history.length) return;

    // 2. Agregacja odtworzeń według utworu
    const songMap = {};

    history.forEach((item) => {
      const song = item.song;
      if (!song) return;

      // Identyfikujemy utwór po ID lub pełnym tekście artist - title
      const id = song.id || `${song.artist}_${song.title}`;

      if (!songMap[id]) {
        songMap[id] = {
          title: song.title || "–",
          artist: song.artist || "–",
          text: song.text || "–",
          plays: 0,
        };
      }
      songMap[id].plays += 1;
    });

    // 3. Konwersja na tablicę i sortowanie od najpopularniejszych
    const sortedSongs = Object.values(songMap).sort((a, b) => b.plays - a.plays);

    // 4. Pobranie najwyższej liczby odtworzeń do wyliczenia paska %
    const maxPlays = sortedSongs[0]?.plays || 0;

    const tbody = document.getElementById("mostPlayedBody");
    tbody.innerHTML = "";

    // 5. Wyświetlenie TOP 30
    sortedSongs.slice(0, 30).forEach((song, i) => {
      const plays = song.plays;
      const pct = maxPlays ? Math.round((plays / maxPlays) * 100) : 0;
      const badge = i === 0 ? "gold" : i === 1 ? "silver" : i === 2 ? "bronze" : "";

      const tr = document.createElement("tr");
      tr.innerHTML = `
            <td><span class="rank-badge ${badge}">${i + 1}</span></td>
            <td>${esc(song.title || song.text)}</td>
            <td class="muted">${esc(song.artist)}</td>
            <td class="num">${plays.toLocaleString("pl-PL")}</td>
            <td>
              <div class="bar-pill">
                <div class="bar-track">
                  <div class="bar-fill" style="width:${pct}%"></div>
                </div>
                <span style="font-size:11px;color:var(--text-muted);min-width:28px;text-align:right">${pct}%</span>
              </div>
            </td>`;
      tbody.appendChild(tr);
    });

    document.getElementById("mostPlayedCard").style.display = "block";
    document.getElementById("tracksEmpty").style.display = "none";
  } catch (e) {
    if (DEBUG) console.warn("[loadMostPlayed]", e.message);
    throw e;
  }
}

// ──────────────────────────────────────────────
// ──────────────────────────────────────────────
// LISTENERS — live, odświeżane co 30 s
// ──────────────────────────────────────────────

// Licznik odliczający do następnego odświeżenia (tick co 1 s)
let _listenersTick = 30;
let _listenersTickTimer = null;

function _startListenersTick() {
  if (_listenersTickTimer) clearInterval(_listenersTickTimer);
  _listenersTick = 30;
  _updateListenersTick();
  _listenersTickTimer = setInterval(() => {
    _listenersTick = Math.max(0, _listenersTick - 1);
    _updateListenersTick();
  }, 1000);
}

function _updateListenersTick() {
  const el = document.getElementById("listenersNextRefresh");
  if (el) el.textContent = _listenersTick > 0 ? `Odświeżenie za ${_listenersTick}s` : "Odświeżam…";
}

// Ikona urządzenia na podstawie flagi device
function _deviceIcon(device) {
  if (!device) return "🔊";
  if (device.is_mobile) return "📱";
  if (device.is_browser) return "🌐";
  // odtwarzacze strumieniowe / agregatory
  return "📻";
}

// Formatowanie czasu trwania połączenia
function _fmtDuration(sec) {
  if (!sec || sec <= 0) return "–";
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}g ${m}min`;
  if (m > 0) return `${m}min ${s}s`;
  return `${s}s`;
}

// Skrócona nazwa streamu z mount_name
function _mountBadge(mountName) {
  if (!mountName) return "";
  if (/HLS/i.test(mountName)) return '<span class="mount-badge mount-hls">HLS</span>';
  if (/MP3/i.test(mountName)) return '<span class="mount-badge mount-mp3">MP3</span>';
  if (/AAC/i.test(mountName)) return '<span class="mount-badge mount-aac">AAC</span>';
  return `<span class="mount-badge">${esc(mountName.split(" ")[0])}</span>`;
}

async function loadListeners() {
  try {
    const d = await apiFetch(`/station/${getSid()}/listeners`);
    const items = Array.isArray(d) ? d : d.listeners || d.results || [];

    // ── TOP 30 według czasu trwania połączenia ──
    const top30 = [...items].sort((a, b) => (b.connected_time || 0) - (a.connected_time || 0)).slice(0, 30);

    // ── Geo aggregation (cała lista) ──
    const geo = {};
    const ua = {};
    items.forEach((it) => {
      const country = it.location?.country || "Nieznany";
      geo[country] = (geo[country] || 0) + 1;

      const client = it.device?.client || it.user_agent?.split(" ")[0] || "Inny";
      ua[client] = (ua[client] || 0) + 1;
    });

    // Geo list
    const geoEl = document.getElementById("geoList");
    const geoSorted = Object.entries(geo)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 10);
    const geoMax = geoSorted[0]?.[1] || 1;
    geoEl.innerHTML = "";
    geoSorted.forEach(([country, cnt]) => {
      const div = document.createElement("div");
      div.className = "geo-row";
      div.innerHTML = `<div class="geo-name">${esc(country)}</div><div class="geo-track"><div class="geo-fill" style="width:${Math.round((cnt / geoMax) * 100)}%"></div></div><div class="geo-count">${cnt}</div>`;
      geoEl.appendChild(div);
    });

    // UA chart
    const uaSorted = Object.entries(ua)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 7);
    makeChart(
      "chartUA",
      "bar",
      uaSorted.map((x) => x[0]),
      uaSorted.map((x) => x[1]),
      C.violet,
      { borderRadius: 4 },
    );

    document.getElementById("listenersGeo").style.display = "block";

    // ── Tabela połączeń live ──
    const tbody = document.getElementById("listenersBody");
    const now = Math.floor(Date.now() / 1000);

    // Zapisz hash aktualnie zaznaczonego wiersza, żeby nie tracić kontekstu
    const prevSelected = tbody.querySelector("tr.selected")?.dataset?.hash || null;

    tbody.innerHTML = "";

    top30.forEach((it, idx) => {
      const dur = it.connected_time ?? 0;
      const city = it.location?.city || "";
      const country = it.location?.country || it.country || "";
      const region = it.location?.region || "";

      let locationText = "–";
      if (city && country) locationText = `${city}, ${country}`;
      else if (country && region) locationText = `${region}, ${country}`;
      else if (city || country) locationText = city || country;

      // Klient — usuń zbędny suffix OS jeśli jest taki sam jak is_mobile
      const clientFull = esc(it.device?.client || it.user_agent?.split(" ")[0] || "–");
      const icon = _deviceIcon(it.device);
      const mount = _mountBadge(it.mount_name);
      const connectedAt = it.connected_on ? new Date(it.connected_on * 1000).toLocaleTimeString("pl-PL", { hour: "2-digit", minute: "2-digit" }) : "–";

      // Wiersz #1 (najdłużej połączony) dostaje wyróżnienie
      const rowClass = idx === 0 ? "listener-top" : "";
      const selected = it.hash === prevSelected ? " selected" : "";

      const tr = document.createElement("tr");
      tr.className = rowClass + selected;
      tr.dataset.hash = it.hash || "";

      tr.innerHTML = `
        <td class="num" style="width:28px;color:var(--text-muted);font-size:11px">${idx + 1}</td>
        <td class="muted" style="font-family:monospace;font-size:11px">${esc(it.ip || "–")}</td>
        <td>${esc(locationText)}</td>
        <td title="${clientFull}" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <span style="margin-right:4px">${icon}</span>${clientFull}
        </td>
        <td>${mount}</td>
        <td class="num" style="font-variant-numeric:tabular-nums">${_fmtDuration(dur)}</td>
        <td class="muted">${connectedAt}</td>`;
      tbody.appendChild(tr);
    });

    // Nagłówek karty
    document.getElementById("listenersCount").textContent = `${items.length} aktywnych · top ${top30.length} wg czasu`;

    // Czas ostatniego odświeżenia
    const tsEl = document.getElementById("listenersLastUpdate");
    if (tsEl) tsEl.textContent = "Odświeżono: " + new Date().toLocaleTimeString("pl-PL", { hour: "2-digit", minute: "2-digit", second: "2-digit" });

    document.getElementById("listenersTableCard").style.display = "block";
    document.getElementById("listenersEmpty").style.display = "none";

    // Resetuj licznik odliczania
    _startListenersTick();
  } catch (e) {
    if (DEBUG) console.warn("[loadListeners]", e.message);
    throw e;
  }
}

// Uruchom / zrestartuj timer co 30 s dla zakładki Połączenia
function startListenersPolling() {
  if (listenersTimer) clearInterval(listenersTimer);
  listenersTimer = setInterval(() => {
    loadListeners().catch((e) => {
      if (DEBUG) console.warn("[listenersPolling]", e.message);
    });
  }, 30_000);
}

// ──────────────────────────────────────────────
// REQUESTS
// ──────────────────────────────────────────────
async function loadRequests() {
  try {
    const d = await apiFetch(`/station/${getSid()}/reports/requests`);

    const items = Array.isArray(d) ? d : d.requests || d.results || [];
    if (!items.length) return;

    const max = Math.max(...items.map((it) => Number(it.count || it.request_count || 1)));
    const tbody = document.getElementById("requestsBody");
    tbody.innerHTML = "";
    items.slice(0, 30).forEach((it, i) => {
      const song = it.song || it.track || it;
      const cnt = Number(it.count || it.request_count || 1);
      const pct = max ? Math.round((cnt / max) * 100) : 0;
      const badge = i === 0 ? "gold" : i === 1 ? "silver" : i === 2 ? "bronze" : "";
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td><span class="rank-badge ${badge}">${i + 1}</span></td>
        <td>${esc(song.title || song.text || "–")}</td>
        <td class="muted">${esc(song.artist || "–")}</td>
        <td class="num">${cnt.toLocaleString("pl-PL")}</td>
        <td><div class="bar-pill"><div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:var(--live)"></div></div><span style="font-size:11px;color:var(--text-muted);min-width:28px;text-align:right">${pct}%</span></div></td>`;
      tbody.appendChild(tr);
    });
    document.getElementById("requestsCard").style.display = "block";
    document.getElementById("requestsEmpty").style.display = "none";
  } catch (e) {
    if (DEBUG) console.warn("[loadRequests]", e.message);
    throw e;
  }
}

// ──────────────────────────────────────────────
// UTILS
// ──────────────────────────────────────────────
function esc(s) {
  return String(s || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

// ──────────────────────────────────────────────
// MAIN
// ──────────────────────────────────────────────
window.addEventListener("load", () => {
  //const today = new Date();
  //const ago30 = new Date(today - 30 * 24 * 3600 * 1000);
  //const fmt = (d) => d.toISOString().slice(0, 10);

  loadAll();

  // Załaduj od razu dane live (endpoint publiczny, GET)
  /*
    loadNowPlaying()
    .then(() => {
      document.getElementById("liveStrip").style.display = "flex";
    })
    .then(() => {
      loadAll();
      apiFetch(`/station/${getSid()}`).then((d) => {
        renderStationDashboard(d);
      });
    });
  */
});
