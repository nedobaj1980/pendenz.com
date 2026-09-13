// assets/js/benutzer.js
// CSP-freundlich (keine Inline-Scripts). Robuster mit CSS.escape-Polyfill.

(function () {
  "use strict";

  // --- Polyfill für CSS.escape (falls Browser/Engine fehlt) ---
  if (typeof window.CSS === "undefined") window.CSS = {};
  if (typeof window.CSS.escape !== "function") {
    window.CSS.escape = function (value) {
      return String(value).replace(/[^a-zA-Z0-9_\-]/g, function (ch) {
        const hex = ch.charCodeAt(0).toString(16).toUpperCase();
        return "\\" + hex + " ";
      });
    };
  }

  const LEVELS = ["private", "internal", "project", "public"];
  const ICON = { private: "🔒", internal: "🏢", project: "👥", public: "🌍" };
  const LABEL = { private: "Nur ich", internal: "Intern", project: "Projekt", public: "Öffentlich" };

  const qs  = (sel, ctx = document) => ctx.querySelector(sel);
  const qsa = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  const nextLevel = (current) => LEVELS[(LEVELS.indexOf(current) + 1) % LEVELS.length];

  // === Sichtbarkeits-Icon: Zyklus durch 🔒/🏢/👥/🌍 ===
  function initVisibilityToggles() {
    qsa(".icon-toggle").forEach((btn) => {
      const key = btn.getAttribute("data-for");
      if (!key) return;
      const hidden = qs(`#vis_${CSS.escape(key)}`);
      if (!hidden) return;

      // Initiale Beschriftung (Fallback auf "private")
      const current = hidden.value && LEVELS.includes(hidden.value) ? hidden.value : "private";
      btn.textContent = ICON[current];
      btn.title = LABEL[current];
      hidden.value = current;

      // Falls das zugehörige Feld disabled ist, Button optisch deaktivieren
      const relatedInput = qs(`#f_${CSS.escape(key)}`);
      const isDisabled = relatedInput && relatedInput.disabled;
      btn.tabIndex = isDisabled ? -1 : 0;
      btn.disabled = !!isDisabled;

      btn.addEventListener("click", () => {
        if (btn.disabled) return;
        const now = hidden.value && LEVELS.includes(hidden.value) ? hidden.value : "private";
        const nx = nextLevel(now);
        hidden.value = nx;
        btn.textContent = ICON[nx];
        btn.title = LABEL[nx];
      });
    });
  }

  // === Range <-> Number Sync + Live-Preview der Größen ===
  function linkRangeAndNumber(rangeId, numberId, onChange) {
    const r = qs(`#${CSS.escape(rangeId)}`);
    const n = qs(`#${CSS.escape(numberId)}`);
    if (!r || !n) return;

    const sync = (from, to) => {
      to.value = from.value;
      if (typeof onChange === "function") onChange(parseInt(from.value, 10));
    };

    // Initial
    sync(r, n);

    r.addEventListener("input", () => sync(r, n));
    n.addEventListener("input", () => {
      let val = parseInt(n.value, 10);
      const min = parseInt(r.min || "0", 10);
      const max = parseInt(r.max || "9999", 10);
      if (isFinite(val)) {
        val = Math.max(min, Math.min(max, val));
        n.value = String(val);
        r.value = String(val);
        if (typeof onChange === "function") onChange(val);
      }
    });
  }

  function initSizeControls() {
    // Profilbild
    linkRangeAndNumber("profilbild_max_size", "profilbild_max_size_num", (px) => {
      qsa("img.img-thumb[alt='Profilbild']").forEach((img) => { img.style.maxWidth = px + "px"; });
      qsa("img[alt='Profilbild']:not(.img-thumb)").forEach((img) => { img.style.maxWidth = px + "px"; });
    });

    // Firmenlogo
    linkRangeAndNumber("firmenlogo_max_size", "firmenlogo_max_size_num", (px) => {
      qsa("img.img-thumb[alt='Firmenlogo'], img[alt='Firmenlogo']").forEach((img) => {
        img.style.maxWidth = px + "px";
      });
    });

    // Titelbild
    linkRangeAndNumber("titelbild_max_height", "titelbild_max_height_num", (px) => {
      qsa("img.img-thumb[alt='Titelbild'], .top-title img").forEach((img) => {
        img.style.maxHeight = px + "px";
        img.style.objectFit = "cover";
      });
    });
  }

  // === File Inputs: Live-Bildvorschau (falls neues Bild gewählt) ===
  function initFilePreviews() {
    const map = [
      { input: "input[name='profilbild']", selector: "img[alt='Profilbild']" },
      { input: "input[name='firmenlogo']", selector: "img[alt='Firmenlogo']" },
      { input: "input[name='titelbild']",  selector: "img[alt='Titelbild'], .top-title img" },
    ];

    map.forEach(({ input, selector }) => {
      const inp = qs(input);
      if (!inp) return;
      inp.addEventListener("change", () => {
        const file = inp.files && inp.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        qsa(selector).forEach((img) => { img.src = url; });
      });
    });
  }

  // === Tabelle: Löschen/Einladung ===
  function initActions() {
    qsa(".js-delete-user").forEach((a) => {
      a.addEventListener("click", (e) => {
        const tr = a.closest("tr");
        const uid = tr ? tr.getAttribute("data-user-id") : "";
        const name = tr ? (tr.querySelector("td:nth-child(2)")?.textContent?.trim() || "") : "";
        if (!confirm(`Benutzer ${name || ("#"+uid)} wirklich löschen?`)) e.preventDefault();
      });
    });

    qsa(".js-invite").forEach((btn) => {
      btn.addEventListener("click", () => {
        const userId = btn.getAttribute("data-user-id");
        alert(`Einladung senden – Benutzer #${userId}\n(Backend-Endpoint noch nicht verdrahtet)`);
      });
    });
  }

  function initAccordionUX() {
    const groups = qsa(".accordion .row-of-four");
    groups.forEach((group) => {
      const items = qsa("details", group);
      if (!items.some((d) => d.hasAttribute("open")) && items[0]) items[0].setAttribute("open", "");
      items.forEach((d) => {
        d.addEventListener("toggle", () => {
          if (d.open) {
            items.forEach((o) => { if (o !== d) o.removeAttribute("open"); });
            d.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "start" });
          }
        });
      });
    });
  }

  function initHashScroll() {
    if (location.hash === "#profile") {
      const el = qs("#profile");
      if (el) el.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    initVisibilityToggles();
    initSizeControls();
    initFilePreviews();
    initActions();
    initAccordionUX();
    initHashScroll();
  });
})();
