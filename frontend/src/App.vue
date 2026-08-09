<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";

const THEME_STORAGE_KEY = "elmo-scanner-theme";
const COOKIE_NOTICE_STORAGE_KEY = "elmo-scanner-cookie-notice";
const BULK_REFRESH_STALE_MINUTES = 5;
const MOBILE_LAYOUT_QUERY = "(max-width: 1020px)";
const MOBILE_HEADER_COLLAPSE_THRESHOLD = 96;
const MOBILE_HEADER_EXPAND_THRESHOLD = 44;
const LEGAL_SECTIONS = {
  impressum: "Impressum",
  datenschutz: "Datenschutzerklaerung",
  cookies: "Cookie-Hinweise",
};
const LEGAL_SECTION_KEYS = Object.keys(LEGAL_SECTIONS);

function resolveInitialTheme() {
  if (typeof window === "undefined") {
    return "light";
  }

  const storedTheme = window.localStorage.getItem(THEME_STORAGE_KEY);
  if (storedTheme === "light" || storedTheme === "dark") {
    return storedTheme;
  }

  return window.matchMedia("(prefers-color-scheme: dark)").matches
    ? "dark"
    : "light";
}

function applyTheme(nextTheme) {
  if (typeof document === "undefined") {
    return;
  }

  document.documentElement.setAttribute("data-theme", nextTheme);
  document.documentElement.style.colorScheme = nextTheme;
}

const apiBase =
  import.meta.env.VITE_API_BASE_URL ||
  import.meta.env.VITE_API_BASE ||
  import.meta.env.VITE_API_URL ||
  "";
// Betreiberangaben kommen aus der Umgebung, nie aus dem Quelltext: sonst liegt
// eine ladungsfähige Anschrift in einem öffentlichen Repository. Fehlt ein
// Pflichtfeld, steht ein sichtbarer Platzhalter auf der Seite statt einer
// leeren Zeile — ein unvollständiges Impressum soll auffallen.
// Die Zugriffe stehen einzeln und ausgeschrieben da: Vite ersetzt
// `import.meta.env.VITE_X` beim Build statisch, ein dynamischer Schlüssel
// (`import.meta.env[key]`) wird nicht ersetzt und wäre in Produktion leer.
const legalRequired = (key, value) =>
  value && String(value).trim() ? String(value).trim() : `[bitte ${key} setzen]`;

const legalName = legalRequired("VITE_LEGAL_NAME", import.meta.env.VITE_LEGAL_NAME);
const legalEmail = legalRequired("VITE_LEGAL_EMAIL", import.meta.env.VITE_LEGAL_EMAIL);
const legalAddressLine1 = legalRequired(
  "VITE_LEGAL_ADDRESS_LINE_1",
  import.meta.env.VITE_LEGAL_ADDRESS_LINE_1,
);
const legalAddressLine2 = legalRequired(
  "VITE_LEGAL_ADDRESS_LINE_2",
  import.meta.env.VITE_LEGAL_ADDRESS_LINE_2,
);
const legalCountry = import.meta.env.VITE_LEGAL_COUNTRY || "Deutschland";
const legalContentResponsible = legalRequired(
  "VITE_LEGAL_CONTENT_RESPONSIBLE",
  import.meta.env.VITE_LEGAL_CONTENT_RESPONSIBLE,
);

const feeds = ref([]);
const feedItems = ref({});
const search = ref("");
const loading = ref(false);
const loadingFeedId = ref(null);
const message = ref("");
const error = ref("");
const selectedFeedId = ref(null);
const isMobileLayout = ref(false);
const isMobileHeaderCollapsed = ref(false);
const contentRef = ref(null);
const sourceListRef = ref(null);
const sidebarTab = ref("feeds");
const activeTag = ref("");
const draggedFeed = ref(null);
const editingFeed = ref(null);
const editForm = ref({ url: "", title: "" });
const deletingFeed = ref(null);
const fetchingAll = ref(false);
const searchInputRef = ref(null);
const theme = ref(resolveInitialTheme());
const previewItem = ref(null);
const previewReader = ref(null);
const previewLoading = ref(false);
const activeLegalSection = ref("");
const showCookieNotice = ref(false);
const legalCardRef = ref(null);
const currentYear = new Date().getFullYear();
let previewRequestId = 0;
let mobileLayoutMediaQuery = null;

const themeLabel = computed(() =>
  theme.value === "dark" ? "Hellmodus aktivieren" : "Dark Mode aktivieren",
);

const legalSectionTitle = computed(
  () => LEGAL_SECTIONS[activeLegalSection.value] || "Rechtliche Hinweise",
);

const previewBodyHtml = computed(() => {
  if (previewReader.value?.html) {
    return previewReader.value.html;
  }

  return buildSummaryHtml(previewItem.value?.summary || "");
});

const form = ref({
  url: "",
  title: "",
});

applyTheme(theme.value);

watch(theme, (nextTheme) => {
  applyTheme(nextTheme);

  if (typeof window !== "undefined") {
    window.localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
  }
});

const relativeTimeFormatter = new Intl.RelativeTimeFormat("de", {
  numeric: "auto",
});

const feedCards = computed(() =>
  feeds.value
    .map((feed) => {
      const items = feedItems.value[feed.id] || [];
      const query = search.value.trim().toLowerCase();
      const filteredItems = items.filter((item) => {
        const title = (item.title || "").toLowerCase();
        const summary = (item.summary || "").toLowerCase();
        const hasSearchMatch =
          query === "" || title.includes(query) || summary.includes(query);

        const categories = Array.isArray(item.categories)
          ? item.categories
          : [];
        const hasTagMatch =
          activeTag.value === "" ||
          categories.some((category) => category === activeTag.value);

        return hasSearchMatch && hasTagMatch;
      });

      return {
        feed,
        items: filteredItems,
        totalItems: items.length,
      };
    })
    .filter((entry) => {
      const queryActive = search.value.trim() !== "";
      const tagActive = activeTag.value !== "";

      if (!queryActive && !tagActive) {
        return true;
      }

      return entry.items.length > 0;
    }),
);

const activeFeedCardId = computed(() => {
  const availableIds = feedCards.value.map((entry) => entry.feed.id);

  if (availableIds.length === 0) {
    return null;
  }

  if (selectedFeedId.value && availableIds.includes(selectedFeedId.value)) {
    return selectedFeedId.value;
  }

  return availableIds[0];
});

const visibleFeedCards = computed(() => {
  if (!isMobileLayout.value) {
    return feedCards.value;
  }

  if (!activeFeedCardId.value) {
    return [];
  }

  return feedCards.value.filter(
    (entry) => entry.feed.id === activeFeedCardId.value,
  );
});

watch(activeFeedCardId, (nextFeedId) => {
  if (nextFeedId !== selectedFeedId.value) {
    selectedFeedId.value = nextFeedId;
  }
});

async function centerActiveSourceItem() {
  if (!isMobileLayout.value || sidebarTab.value !== "feeds") {
    return;
  }

  await nextTick();

  const activeFeedId = activeFeedCardId.value;
  if (!activeFeedId) {
    return;
  }

  const sourceListElement = sourceListRef.value;
  if (!(sourceListElement instanceof HTMLElement)) {
    return;
  }

  const activeButton = sourceListElement.querySelector(
    `.source-item[data-feed-id="${activeFeedId}"]`,
  );

  if (!(activeButton instanceof HTMLElement)) {
    return;
  }

  activeButton.scrollIntoView({
    behavior: "smooth",
    inline: "center",
    block: "nearest",
  });
}

watch([activeFeedCardId, isMobileLayout, sidebarTab], () => {
  centerActiveSourceItem();
});

const tagStats = computed(() => {
  const counts = new Map();

  for (const items of Object.values(feedItems.value)) {
    for (const item of items) {
      const categories = Array.isArray(item.categories) ? item.categories : [];
      for (const category of categories) {
        const normalized = String(category).trim();
        if (normalized === "") {
          continue;
        }
        counts.set(normalized, (counts.get(normalized) || 0) + 1);
      }
    }
  }

  return Array.from(counts.entries())
    .map(([name, count]) => ({ name, count }))
    .sort((a, b) => {
      if (b.count !== a.count) {
        return b.count - a.count;
      }
      return a.name.localeCompare(b.name, "de");
    });
});

const filteredTagItemCountByFeed = computed(() => {
  if (!activeTag.value) {
    return {};
  }

  const counts = {};

  for (const feed of feeds.value) {
    const items = feedItems.value[feed.id] || [];
    counts[feed.id] = items.filter((item) => {
      const categories = Array.isArray(item.categories) ? item.categories : [];
      return categories.some((category) => category === activeTag.value);
    }).length;
  }

  return counts;
});

function isFeedDisabledByTagFilter(feedId) {
  if (!activeTag.value) {
    return false;
  }

  return (filteredTagItemCountByFeed.value[feedId] || 0) === 0;
}

const bulkRefreshEligibleCount = computed(
  () => feeds.value.filter((feed) => isFeedEligibleForBulkRefresh(feed)).length,
);

const bulkRefreshButtonTitle = computed(() => {
  if (fetchingAll.value) {
    return bulkRefreshEligibleCount.value > 0
      ? "Fällige Feeds werden aktualisiert"
      : "Alle Feeds werden aktualisiert";
  }

  if (bulkRefreshEligibleCount.value === 0) {
    return "Keine fälligen Feeds - alle Feeds aktualisieren";
  }

  return `${bulkRefreshEligibleCount.value} Feed${bulkRefreshEligibleCount.value === 1 ? "" : "s"} aktualisieren`;
});

async function apiRequest(path, options = {}) {
  const response = await fetch(`${apiBase}${path}`, {
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {}),
    },
    ...options,
  });

  const contentType = response.headers.get("content-type") || "";

  if (!response.ok) {
    const body = await response.text();

    if (contentType.includes("application/json")) {
      try {
        const parsed = JSON.parse(body);
        const message = parsed?.message || parsed?.error;
        throw new Error(
          message ||
            `Request failed (${response.status} ${response.statusText})`,
        );
      } catch {
        throw new Error(
          `Request failed (${response.status} ${response.statusText})`,
        );
      }
    }

    if (contentType.includes("text/html")) {
      throw new Error(
        `Server antwortet mit HTML statt JSON (${response.status} ${response.statusText}). Pruefe API-URL und Backend-Status.`,
      );
    }

    throw new Error(
      body?.trim() ||
        `Request failed (${response.status} ${response.statusText})`,
    );
  }

  if (!contentType.includes("application/json")) {
    throw new Error(
      `Unerwartete Antwort vom Server (${response.status} ${response.statusText}).`,
    );
  }

  return response.json();
}

async function loadFeeds() {
  const result = await apiRequest("/api/feeds?per_page=100");
  feeds.value = result.data || [];

  if (!selectedFeedId.value && feeds.value.length > 0) {
    selectedFeedId.value = feeds.value[0].id;
  }
}

async function loadFeedItems(feedId) {
  const result = await apiRequest(
    `/api/articles?feed_id=${feedId}&per_page=100`,
  );
  feedItems.value = {
    ...feedItems.value,
    [feedId]: result.data || [],
  };
}

async function loadAllFeedItems() {
  const tasks = feeds.value.map((feed) => loadFeedItems(feed.id));
  await Promise.all(tasks);
}

async function bootstrap() {
  loading.value = true;
  error.value = "";

  try {
    await loadFeeds();
    await loadAllFeedItems();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Laden fehlgeschlagen.";
  } finally {
    loading.value = false;
  }
}

async function addFeed() {
  if (!form.value.url.trim()) {
    error.value = "Bitte eine gueltige URL eingeben.";
    return;
  }

  loading.value = true;
  error.value = "";
  message.value = "";

  try {
    await apiRequest("/api/feeds", {
      method: "POST",
      body: JSON.stringify({
        url: form.value.url.trim(),
        title: form.value.title.trim() || null,
      }),
    });

    form.value.url = "";
    form.value.title = "";
    message.value = "Feed wurde hinzugefuegt.";

    await loadFeeds();
    await loadAllFeedItems();
  } catch (e) {
    error.value =
      e instanceof Error ? e.message : "Feed konnte nicht gespeichert werden.";
  } finally {
    loading.value = false;
  }
}

async function fetchFeed(feedId) {
  loadingFeedId.value = feedId;
  error.value = "";
  message.value = "";

  try {
    const result = await apiRequest(`/api/admin/feeds/${feedId}/fetch`, {
      method: "POST",
    });

    message.value = result.output || "Feed erfolgreich aktualisiert.";

    await loadFeedItems(feedId);
    await loadFeeds();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Fetch fehlgeschlagen.";
  } finally {
    loadingFeedId.value = null;
  }
}

async function selectFeed(feedId) {
  selectedFeedId.value = feedId;
  sidebarTab.value = "feeds";

  await nextTick();

  const contentElement = contentRef.value;

  if (isMobileLayout.value && contentElement instanceof HTMLElement) {
    isMobileHeaderCollapsed.value = false;
    contentElement.scrollTo({
      top: 0,
      behavior: "smooth",
    });
    return;
  }

  const card = document.getElementById(`feed-card-${feedId}`);

  if (!card) {
    return;
  }

  if (contentElement instanceof HTMLElement) {
    const cardTop = card.offsetTop - contentElement.offsetTop;
    contentElement.scrollTo({
      top: Math.max(cardTop - 8, 0),
      behavior: "smooth",
    });
    return;
  }

  card.scrollIntoView({ behavior: "smooth", block: "start" });
}

function setSidebarTab(tabName) {
  sidebarTab.value = tabName;
}

function toggleTagFilter(tagName) {
  activeTag.value = activeTag.value === tagName ? "" : tagName;
}

function clearTagFilter() {
  activeTag.value = "";
}

function handleDragStart(feed) {
  draggedFeed.value = feed;
}

function handleDragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = "move";
}

async function handleDrop(targetFeed) {
  if (!draggedFeed.value || draggedFeed.value.id === targetFeed.id) {
    draggedFeed.value = null;
    return;
  }

  const draggedIndex = feeds.value.findIndex(
    (f) => f.id === draggedFeed.value.id,
  );
  const targetIndex = feeds.value.findIndex((f) => f.id === targetFeed.id);

  if (draggedIndex === -1 || targetIndex === -1) {
    draggedFeed.value = null;
    return;
  }

  // Swap in local state
  const newFeeds = [...feeds.value];
  [newFeeds[draggedIndex], newFeeds[targetIndex]] = [
    newFeeds[targetIndex],
    newFeeds[draggedIndex],
  ];
  feeds.value = newFeeds;

  // Update backend with new positions
  try {
    const feedsWithPositions = feeds.value.map((feed, index) => ({
      id: feed.id,
      position: index,
    }));

    await apiRequest("/api/feeds/reorder", {
      method: "PATCH",
      body: JSON.stringify({ feeds: feedsWithPositions }),
    });
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Reorder fehlgeschlagen.";
    // Reload feeds to restore original order on error
    await loadFeeds();
  } finally {
    draggedFeed.value = null;
  }
}

function formatRelativeTime(value) {
  if (!value) {
    return "Zeit unbekannt";
  }

  const timestamp = new Date(value);
  if (Number.isNaN(timestamp.getTime())) {
    return "Zeit unbekannt";
  }

  const diffMs = timestamp.getTime() - Date.now();
  const minute = 60 * 1000;
  const hour = 60 * minute;
  const day = 24 * hour;
  const week = 7 * day;

  const units = [
    { unit: "week", ms: week },
    { unit: "day", ms: day },
    { unit: "hour", ms: hour },
    { unit: "minute", ms: minute },
  ];

  for (const { unit, ms } of units) {
    if (Math.abs(diffMs) >= ms) {
      return relativeTimeFormatter.format(Math.round(diffMs / ms), unit);
    }
  }

  return "gerade eben";
}

function isFeedEligibleForBulkRefresh(feed) {
  if (!feed?.is_active) {
    return false;
  }

  if (!feed.last_fetched_at) {
    return true;
  }

  const timestamp = new Date(feed.last_fetched_at);
  if (Number.isNaN(timestamp.getTime())) {
    return true;
  }

  return (
    Date.now() - timestamp.getTime() >= BULK_REFRESH_STALE_MINUTES * 60 * 1000
  );
}

function isFeedStaleWithError(feed) {
  return isFeedEligibleForBulkRefresh(feed) && Boolean(feed?.last_error);
}

function buildFetchAllMessage(result) {
  const mode = result?.mode === "all" ? "all" : "stale";
  const refreshedCount = Number(result?.refreshed_count || 0);
  const refreshedFeedTitles = Array.isArray(result?.refreshed_feed_titles)
    ? result.refreshed_feed_titles.filter(Boolean)
    : [];
  const skippedCount = Number(result?.skipped_count || 0);
  const failedCount = Number(result?.failed_count || 0);

  if (
    mode === "stale" &&
    refreshedCount === 0 &&
    skippedCount > 0 &&
    failedCount === 0
  ) {
    return `Keine fälligen Feeds. ${skippedCount} übersprungen.`;
  }

  const parts = [
    `${refreshedCount} aktualisiert`,
    `${skippedCount} übersprungen`,
  ];

  if (failedCount > 0) {
    parts.push(`${failedCount} fehlgeschlagen`);
  }

  const prefix = mode === "all" ? "Alle-Feeds-Refresh" : "Feed-Refresh";

  if (refreshedFeedTitles.length === 0) {
    return `${prefix}: ${parts.join(", ")}.`;
  }

  const visibleTitles = refreshedFeedTitles.slice(0, 3);
  const remainingCount = refreshedFeedTitles.length - visibleTitles.length;
  const feedList =
    remainingCount > 0
      ? `${visibleTitles.join(", ")} +${remainingCount} weitere`
      : visibleTitles.join(", ");

  return `${prefix}: ${parts.join(", ")}. Aktualisiert: ${feedList}.`;
}

function getFeedHost(feed) {
  const rawUrl = String(feed?.url || "").trim();

  if (!rawUrl) {
    return "";
  }

  try {
    return new URL(rawUrl).hostname.replace(/^www\./, "");
  } catch {
    return "";
  }
}

function getFeedLabel(feed) {
  const title = String(feed?.title || "").trim();

  if (title) {
    return title;
  }

  const host = getFeedHost(feed);
  return host || "Unbekannte Quelle";
}

function getFeedBadge(feed) {
  const source = getFeedLabel(feed)
    .split(/\s+/)
    .map((part) => part.trim())
    .filter(Boolean);

  if (source.length === 0) {
    return "?";
  }

  if (source.length === 1) {
    return source[0].slice(0, 2).toUpperCase();
  }

  return source
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");
}

async function openLegalSection(section) {
  if (!LEGAL_SECTION_KEYS.includes(section)) {
    return;
  }

  activeLegalSection.value = section;

  if (typeof window !== "undefined") {
    const nextUrl = `${window.location.pathname}${window.location.search}#${section}`;
    window.history.replaceState({}, "", nextUrl);
  }

  await nextTick();
  legalCardRef.value?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function closeLegalSection() {
  activeLegalSection.value = "";

  if (typeof window !== "undefined") {
    const currentHash = window.location.hash.replace(/^#/, "");
    if (LEGAL_SECTION_KEYS.includes(currentHash)) {
      const nextUrl = `${window.location.pathname}${window.location.search}`;
      window.history.replaceState({}, "", nextUrl);
    }
  }
}

function handleHashChange() {
  if (typeof window === "undefined") {
    return;
  }

  const currentHash = window.location.hash.replace(/^#/, "");
  activeLegalSection.value = LEGAL_SECTION_KEYS.includes(currentHash)
    ? currentHash
    : "";
}

function acceptCookieNotice() {
  showCookieNotice.value = false;

  if (typeof window !== "undefined") {
    window.localStorage.setItem(COOKIE_NOTICE_STORAGE_KEY, "accepted");
  }
}

function openCookieDetails() {
  openLegalSection("cookies");
}

function getItemFeed(item, fallbackFeed = null) {
  return item?.feed || fallbackFeed || null;
}

function getFeedFetchLabel(feed) {
  if (!feed?.last_fetched_at) {
    return "nie";
  }

  return formatRelativeTime(feed.last_fetched_at);
}

const filteredFeeds = computed(() => {
  const query = search.value.trim().toLowerCase();
  if (!query) return feeds.value;
  return feeds.value.filter((feed) => {
    const title = (feed.title || "").toLowerCase();
    const url = (feed.url || "").toLowerCase();
    return title.includes(query) || url.includes(query);
  });
});

function focusSearch() {
  searchInputRef.value?.focus();
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function buildSummaryHtml(summary) {
  const normalized = String(summary || "").trim();
  if (!normalized) {
    return "<p>Keine Vorschau verfuegbar.</p>";
  }

  return normalized
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean)
    .map((paragraph) => `<p>${escapeHtml(paragraph)}</p>`)
    .join("");
}

function toggleTheme() {
  theme.value = theme.value === "dark" ? "light" : "dark";
}

async function openArticlePreview(item) {
  previewItem.value = item;
  previewReader.value = {
    html: buildSummaryHtml(item.summary || ""),
    source: "summary",
    cached: true,
    error: null,
  };
  previewLoading.value = true;

  const requestId = ++previewRequestId;

  try {
    const article = await apiRequest(`/api/articles/${item.id}`);

    if (requestId !== previewRequestId) {
      return;
    }

    previewItem.value = article;
    previewReader.value = article.reader || previewReader.value;
  } catch (e) {
    if (requestId !== previewRequestId) {
      return;
    }

    previewReader.value = {
      ...(previewReader.value || {}),
      error:
        e instanceof Error
          ? e.message
          : "Vorschau konnte nicht geladen werden.",
    };
  } finally {
    if (requestId === previewRequestId) {
      previewLoading.value = false;
    }
  }
}

function closeArticlePreview() {
  previewRequestId += 1;
  previewItem.value = null;
  previewReader.value = null;
  previewLoading.value = false;
}

function openArticleInNewTab() {
  if (!previewItem.value?.url) {
    return;
  }

  window.open(previewItem.value.url, "_blank", "noopener,noreferrer");
}

function getDisplayCategories(item) {
  if (!Array.isArray(item?.categories)) {
    return [];
  }

  return item.categories
    .map((category) => String(category || "").trim())
    .filter(
      (category) =>
        category !== "" && category.toLowerCase() !== "uncategorized",
    );
}

function openEditModal(feed) {
  editingFeed.value = feed;
  editForm.value = { url: feed.url, title: feed.title || "" };
}

function closeEditModal() {
  editingFeed.value = null;
  editForm.value = { url: "", title: "" };
}

async function saveEdit() {
  if (!editForm.value.url.trim()) {
    error.value = "URL ist erforderlich.";
    return;
  }

  loading.value = true;
  error.value = "";

  try {
    await apiRequest(`/api/feeds/${editingFeed.value.id}`, {
      method: "PATCH",
      body: JSON.stringify({
        url: editForm.value.url.trim(),
        title: editForm.value.title.trim() || null,
      }),
    });

    message.value = "Feed aktualisiert.";
    closeEditModal();
    await loadFeeds();
    await loadAllFeedItems();
  } catch (e) {
    error.value =
      e instanceof Error ? e.message : "Aktualisierung fehlgeschlagen.";
  } finally {
    loading.value = false;
  }
}

function openDeleteModal(feed) {
  deletingFeed.value = feed;
}

function closeDeleteModal() {
  deletingFeed.value = null;
}

async function confirmDelete() {
  const feedId = deletingFeed.value.id;
  closeDeleteModal();

  loading.value = true;
  error.value = "";

  try {
    await apiRequest(`/api/feeds/${feedId}`, {
      method: "DELETE",
    });

    message.value = "Feed gelöscht.";
    await loadFeeds();
    await loadAllFeedItems();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Löschen fehlgeschlagen.";
  } finally {
    loading.value = false;
  }
}

async function fetchAllFeeds() {
  fetchingAll.value = true;
  error.value = "";
  message.value = "";
  const forceAll = bulkRefreshEligibleCount.value === 0;

  try {
    const result = await apiRequest("/api/admin/feeds/fetch-all", {
      method: "POST",
      body: JSON.stringify({
        force_all: forceAll,
      }),
    });

    message.value = buildFetchAllMessage(result);
    await loadFeeds();
    await loadAllFeedItems();
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Fetch fehlgeschlagen.";
  } finally {
    fetchingAll.value = false;
  }
}

function handleKeydown(e) {
  if (e.key === "Escape" && previewItem.value) {
    closeArticlePreview();
    return;
  }

  if ((e.metaKey || e.ctrlKey) && e.key === "k") {
    e.preventDefault();
    focusSearch();
  }
}

function handleMobileLayoutChange(event) {
  isMobileLayout.value = event.matches;

  if (!event.matches) {
    isMobileHeaderCollapsed.value = false;
  }
}

function handleWindowScroll() {
  if (!isMobileLayout.value || sidebarTab.value !== "feeds") {
    isMobileHeaderCollapsed.value = false;
    return;
  }

  const contentElement = contentRef.value;
  const scrollTop =
    contentElement instanceof HTMLElement
      ? contentElement.scrollTop
      : window.scrollY;

  if (isMobileHeaderCollapsed.value) {
    if (scrollTop <= MOBILE_HEADER_EXPAND_THRESHOLD) {
      isMobileHeaderCollapsed.value = false;
    }

    return;
  }

  if (scrollTop >= MOBILE_HEADER_COLLAPSE_THRESHOLD) {
    isMobileHeaderCollapsed.value = true;
  }
}

onMounted(() => {
  bootstrap();
  window.addEventListener("keydown", handleKeydown);
  window.addEventListener("scroll", handleWindowScroll, { passive: true });
  window.addEventListener("hashchange", handleHashChange);

  mobileLayoutMediaQuery = window.matchMedia(MOBILE_LAYOUT_QUERY);
  isMobileLayout.value = mobileLayoutMediaQuery.matches;
  mobileLayoutMediaQuery.addEventListener("change", handleMobileLayoutChange);
  showCookieNotice.value =
    window.localStorage.getItem(COOKIE_NOTICE_STORAGE_KEY) !== "accepted";
  handleHashChange();
  handleWindowScroll();
});

onUnmounted(() => {
  window.removeEventListener("keydown", handleKeydown);
  window.removeEventListener("scroll", handleWindowScroll);
  window.removeEventListener("hashchange", handleHashChange);

  if (mobileLayoutMediaQuery) {
    mobileLayoutMediaQuery.removeEventListener(
      "change",
      handleMobileLayoutChange,
    );
  }
});
</script>

<template>
  <main class="layout">
    <aside
      class="sidebar panel"
      :class="{
        'sidebar--sticky-mobile': sidebarTab === 'feeds',
        'sidebar--mobile-collapsed':
          sidebarTab === 'feeds' && isMobileHeaderCollapsed,
      }"
    >
      <div class="sidebar-head">
        <div class="sidebar-title-row">
          <div>
            <h1>Medien Scanner</h1>
            <p class="subtitle">{{ feeds.length }} Quellen</p>
          </div>
          <button
            class="theme-toggle"
            type="button"
            :aria-label="themeLabel"
            :title="themeLabel"
            @click="toggleTheme"
          >
            <span aria-hidden="true">{{ theme === "dark" ? "☀" : "☾" }}</span>
          </button>
        </div>
      </div>

      <div class="sidebar-mobile-header">
        <div class="sidebar-tabs">
          <button
            class="tab-btn"
            :class="{
              active: sidebarTab === 'feeds',
              'tab-btn--has-filter': activeTag,
            }"
            @click="setSidebarTab('feeds')"
            :title="activeTag ? `Filter aktiv: ${activeTag}` : undefined"
          >
            Feeds
            <span
              v-if="activeTag"
              class="filter-badge"
              aria-label="Filter aktiv"
              >🏷️</span
            >
          </button>
          <button
            class="tab-btn"
            :class="{ active: sidebarTab === 'filters' }"
            @click="setSidebarTab('filters')"
          >
            Filter
          </button>
          <button
            class="tab-btn tab-btn--new-feed"
            :class="{ active: sidebarTab === 'new-feed' }"
            @click="setSidebarTab('new-feed')"
          >
            Neuer Feed
          </button>
        </div>

        <div
          class="feeds-toolbar feeds-toolbar--header"
          :class="{
            'feeds-toolbar--desktop-only-feeds': sidebarTab !== 'feeds',
          }"
        >
          <input
            ref="searchInputRef"
            v-model="search"
            type="text"
            placeholder="Suche in Feeds und Artikeln... (Cmd+K)"
            class="feed-search"
          />
          <button
            v-if="activeTag"
            class="clear-filter clear-filter--feeds"
            @click="clearTagFilter"
            :title="`Aktiven Filter '${activeTag}' entfernen`"
          >
            Filter: {{ activeTag }} ×
          </button>
          <button
            :disabled="fetchingAll || loading"
            @click="fetchAllFeeds"
            class="fetch-all-btn"
            :class="{ 'fetch-all-btn--loading': fetchingAll }"
            :title="bulkRefreshButtonTitle"
            :aria-label="bulkRefreshButtonTitle"
          >
            <span aria-hidden="true" class="fetch-icon">↻</span>
            <span
              v-if="bulkRefreshEligibleCount > 0 && !fetchingAll"
              class="fetch-all-count"
              aria-hidden="true"
            >
              {{ bulkRefreshEligibleCount }}
            </span>
          </button>
        </div>
      </div>

      <section v-if="sidebarTab === 'feeds'" class="sidebar-section">
        <div v-if="fetchingAll" class="refresh-status-message" role="status">
          <span class="spinner" aria-hidden="true"></span>
          <span>Feeds werden aktualisiert…</span>
        </div>
        <ul ref="sourceListRef" class="source-list">
          <li
            v-for="feed in filteredFeeds"
            :key="feed.id"
            draggable="true"
            @dragstart="handleDragStart(feed)"
            @dragover="handleDragOver"
            @drop="handleDrop(feed)"
            :class="{ dragging: draggedFeed?.id === feed.id }"
          >
            <button
              class="source-item"
              :data-feed-id="feed.id"
              :class="{
                active: activeFeedCardId === feed.id,
                'source-item--disabled': isFeedDisabledByTagFilter(feed.id),
                'source-item--stale': isFeedEligibleForBulkRefresh(feed),
                'source-item--error': isFeedStaleWithError(feed),
              }"
              :disabled="isFeedDisabledByTagFilter(feed.id)"
              @click="selectFeed(feed.id)"
              :title="
                isFeedDisabledByTagFilter(feed.id)
                  ? `Keine Artikel mit Tag '${activeTag}' in diesem Feed`
                  : isFeedStaleWithError(feed)
                    ? `Fehler beim letzten Update: ${feed.last_error}`
                    : isFeedEligibleForBulkRefresh(feed)
                      ? 'Dieser Feed ist älter als 5 Minuten und kann aktualisiert werden'
                      : undefined
              "
            >
              <div class="source-item-title-row">
                <strong>{{ feed.title || "Ohne Titel" }}</strong>
              </div>
            </button>
            <div class="feed-actions">
              <button
                class="feed-action-btn edit"
                @click.prevent="openEditModal(feed)"
                title="Bearbeiten"
              >
                ✏️
              </button>
              <button
                class="feed-action-btn delete"
                @click.prevent="openDeleteModal(feed)"
                title="Löschen"
              >
                🗑️
              </button>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="sidebarTab === 'filters'" class="sidebar-section">
        <div class="filter-head">
          <p>Tag/Kategorie Filter</p>
          <button v-if="activeTag" class="clear-filter" @click="clearTagFilter">
            Zuruecksetzen
          </button>
        </div>

        <ul class="tag-filter-list" v-if="tagStats.length > 0">
          <li v-for="tag in tagStats" :key="tag.name">
            <button
              class="tag-filter-btn"
              :class="{ active: activeTag === tag.name }"
              @click="toggleTagFilter(tag.name)"
            >
              <span>{{ tag.name }}</span>
              <strong>{{ tag.count }}</strong>
            </button>
          </li>
        </ul>
        <p v-else class="empty-text">Keine Tags/Kategorien verfuegbar.</p>
      </section>

      <section v-if="sidebarTab === 'new-feed'" class="sidebar-section">
        <form class="feed-form" @submit.prevent="addFeed">
          <input
            v-model="form.url"
            type="url"
            placeholder="https://example.com/feed.xml"
            required
          />
          <input
            v-model="form.title"
            type="text"
            placeholder="Optionaler Titel"
          />
          <button :disabled="loading" type="submit">Feed hinzufuegen</button>
        </form>
      </section>
    </aside>

    <section
      ref="contentRef"
      class="content"
      @scroll.passive="handleWindowScroll"
    >
      <div v-if="feedCards.length === 0" class="panel empty">
        Keine Feeds vorhanden.
      </div>

      <article
        v-for="entry in visibleFeedCards"
        :id="`feed-card-${entry.feed.id}`"
        :key="entry.feed.id"
        class="feed-card panel"
      >
        <div
          v-if="activeTag && entry.items.length === 0 && entry.totalItems > 0"
          class="filter-notice"
        >
          <strong>⚠️ Kein Artikel mit Tag "{{ activeTag }}"</strong>
          <p>
            Dieser Feed hat {{ entry.totalItems }} Artikel, aber keine zum Tag
            "{{ activeTag }}".
          </p>
          <button class="filter-notice-clear" @click="clearTagFilter">
            Filter löschen
          </button>
        </div>
        <header class="feed-card-head">
          <div>
            <h3>{{ entry.feed.title || "Ohne Titel" }}</h3>
            <p>{{ entry.feed.url }}</p>
          </div>
          <div class="feed-meta">
            <span
              >{{ entry.items.length }} von {{ entry.totalItems }} Items</span
            >
          </div>
        </header>

        <ul class="item-list" v-if="entry.items.length > 0">
          <li v-for="item in entry.items" :key="item.id">
            <div class="item-row">
              <img
                v-if="item.image_url"
                class="item-image"
                :src="item.image_url"
                :alt="item.title"
                loading="lazy"
                referrerpolicy="no-referrer"
                @click="openArticlePreview(item)"
              />

              <div class="item-body">
                <div class="item-top">
                  <div class="item-source-line">
                    <span class="source-badge source-badge-small">{{
                      getFeedBadge(getItemFeed(item, entry.feed))
                    }}</span>
                    <span
                      class="item-source-name"
                      :title="getFeedLabel(getItemFeed(item, entry.feed))"
                      >{{ getFeedLabel(getItemFeed(item, entry.feed)) }}</span
                    >
                    <span
                      v-if="getFeedHost(getItemFeed(item, entry.feed))"
                      class="item-source-host"
                      :title="getFeedHost(getItemFeed(item, entry.feed))"
                    >
                      {{ getFeedHost(getItemFeed(item, entry.feed)) }}
                    </span>
                    <span class="item-time">{{
                      formatRelativeTime(item.published_at)
                    }}</span>
                  </div>
                  <ul
                    v-if="getDisplayCategories(item).length > 0"
                    class="tag-list"
                  >
                    <li
                      v-for="category in getDisplayCategories(item).slice(0, 1)"
                      :key="`${item.id}-${category}`"
                    >
                      {{ category }}
                    </li>
                    <li
                      v-if="getDisplayCategories(item).length > 1"
                      :key="`${item.id}-more-categories`"
                      :title="`${getDisplayCategories(item).length - 1} weitere Kategorien`"
                    >
                      +{{ getDisplayCategories(item).length - 1 }}
                    </li>
                  </ul>
                </div>

                <button
                  class="item-link"
                  type="button"
                  @click="openArticlePreview(item)"
                >
                  {{ item.title }}
                </button>
                <p v-if="item.summary">{{ item.summary }}</p>
              </div>
            </div>
          </li>
        </ul>
        <p v-else class="empty-text">Keine Items fuer diesen Feed gefunden.</p>
      </article>

      <p v-if="message" class="message panel">{{ message }}</p>
      <p v-if="error" class="error panel">{{ error }}</p>

      <footer class="site-footer panel">
        <div>
          <p class="overline">Rechtliches</p>
          <h2>Impressum, Datenschutz & Cookies</h2>
          <p class="site-footer-meta">
            Aktuell werden keine Analyse- oder Marketing-Cookies eingesetzt ·
            {{ currentYear }}.
          </p>
        </div>

        <div class="site-footer-links">
          <button
            type="button"
            class="site-footer-link"
            @click="openLegalSection('impressum')"
          >
            Impressum
          </button>
          <button
            type="button"
            class="site-footer-link"
            @click="openLegalSection('datenschutz')"
          >
            Datenschutz
          </button>
          <button
            type="button"
            class="site-footer-link"
            @click="openLegalSection('cookies')"
          >
            Cookie-Hinweise
          </button>
        </div>
      </footer>

      <section
        v-if="activeLegalSection"
        :id="activeLegalSection"
        ref="legalCardRef"
        class="legal-card panel"
      >
        <div class="legal-card-head">
          <div>
            <p class="overline">Rechtliche Informationen</p>
            <h2>{{ legalSectionTitle }}</h2>
          </div>
          <button type="button" class="legal-close" @click="closeLegalSection">
            Schliessen
          </button>
        </div>

        <div v-if="activeLegalSection === 'impressum'" class="legal-copy">
          <p><strong>Angaben gemaess § 5 DDG</strong></p>
          <p>
            Diese Website ist ein privates Webprojekt von
            <strong>{{ legalName }}</strong
            >.
          </p>
          <ul class="legal-list">
            <li><strong>Verantwortlicher:</strong> {{ legalName }}</li>
            <li>
              <strong>Ladungsfaehige Anschrift:</strong><br />
              {{ legalAddressLine1 }}<br />
              {{ legalAddressLine2 }}<br />
              {{ legalCountry }}
            </li>
            <li>
              <strong>E-Mail:</strong>
              <a :href="`mailto:${legalEmail}`">{{ legalEmail }}</a>
            </li>
          </ul>
          <p>
            <strong
              >Verantwortlich fuer journalistisch-redaktionelle Inhalte gemaess
              § 18 Abs. 2 MStV:</strong
            >
            {{ legalContentResponsible }}, Anschrift wie oben.
          </p>
          <p>
            Angezeigte Artikeltitel, Vorschaubilder und Verlinkungen stammen aus
            den jeweils eingebundenen RSS-/Atom-Feeds der jeweiligen Anbieter.
          </p>
        </div>

        <div
          v-else-if="activeLegalSection === 'datenschutz'"
          class="legal-copy"
        >
          <p>
            Beim Aufruf der Website werden serverseitig technisch notwendige
            Verbindungsdaten verarbeitet, um die Seite auszuliefern und den
            sicheren Betrieb zu gewaehrleisten.
          </p>
          <ul class="legal-list">
            <li>
              <strong>Server-Logs:</strong> IP-Adresse, Zeitpunkt, aufgerufene
              URL, Browser-Informationen und Statuscode.
            </li>
            <li>
              <strong>Lokale Browser-Speicherung:</strong> Gespeichert werden
              nur die Theme-Einstellung (`elmo-scanner-theme`) und der Status
              dieses Hinweises (`elmo-scanner-cookie-notice`).
            </li>
            <li>
              <strong>Externe Inhalte:</strong> Beim Laden von Artikelbildern
              oder beim Oeffnen externer Quellen kann Ihre IP-Adresse an die
              jeweiligen Feed- oder Medienanbieter uebermittelt werden.
            </li>
          </ul>
          <p>
            Es werden derzeit keine Analyse-, Tracking- oder Marketing-Tools
            eingesetzt. Unnoetige Drittanbieter-Anfragen fuer Favicons wurden
            aus Datenschutzgruenden entfernt.
          </p>
          <p>
            Ihnen stehen nach Massgabe der DSGVO insbesondere Rechte auf
            Auskunft, Berichtigung, Loeschung und Einschraenkung der
            Verarbeitung zu. Anfragen koennen ueber die im Impressum genannte
            E-Mail-Adresse gestellt werden.
          </p>
        </div>

        <div v-else class="legal-copy">
          <p>
            Diese Website verwendet derzeit keine Analyse- oder
            Marketing-Cookies.
          </p>
          <ul class="legal-list">
            <li>
              <strong>Technisch notwendige Speicherung:</strong> Die
              Theme-Auswahl wird lokal im Browser gespeichert, damit die Seite
              beim naechsten Besuch in der gewaehlten Ansicht startet.
            </li>
            <li>
              <strong>Hinweis-Speicherung:</strong> Der Banner merkt sich lokal,
              dass Sie den Cookie- und Datenschutzhinweis bereits gesehen haben.
            </li>
          </ul>
          <p>
            Falls spaeter optionale Tracking-, Analyse- oder Werbedienste
            hinzukommen, sollte vor deren Aktivierung eine ausdrueckliche
            Einwilligung eingeholt werden.
          </p>
        </div>

        <p class="legal-note">
          Die Anbieterangaben werden ueber `VITE_LEGAL_*`-Variablen aus der
          Frontend-Umgebung geladen.
        </p>
      </section>
    </section>

    <!-- Edit Feed Modal -->
    <div v-if="editingFeed" class="modal-overlay" @click="closeEditModal">
      <div class="modal-content" @click.stop>
        <h2>Feed bearbeiten</h2>
        <form @submit.prevent="saveEdit">
          <div class="form-group">
            <label>URL</label>
            <input
              v-model="editForm.url"
              type="url"
              required
              :disabled="loading"
            />
          </div>
          <div class="form-group">
            <label>Titel (optional)</label>
            <input v-model="editForm.title" type="text" :disabled="loading" />
          </div>
          <div class="modal-actions">
            <button type="button" @click="closeEditModal" :disabled="loading">
              Abbrechen
            </button>
            <button type="submit" :disabled="loading">
              {{ loading ? "Speichern..." : "Speichern" }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="deletingFeed" class="modal-overlay" @click="closeDeleteModal">
      <div class="modal-content modal-danger" @click.stop>
        <h2>Feed löschen?</h2>
        <p>
          {{ deletingFeed.title || "Dieser Feed" }} wird permanently gelöscht
          und alle zugehörigen Artikel werden entfernt.
        </p>
        <div class="modal-actions">
          <button @click="closeDeleteModal" :disabled="loading">
            Abbrechen
          </button>
          <button class="btn-danger" @click="confirmDelete" :disabled="loading">
            {{ loading ? "Löschen..." : "Löschen" }}
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="previewItem"
      class="modal-overlay article-preview-overlay"
      @click="closeArticlePreview"
    >
      <div class="modal-content article-preview-modal" @click.stop>
        <div class="article-preview-head">
          <div>
            <div class="article-preview-title-row">
              <h2>{{ previewItem.title || "Ohne Titel" }}</h2>
              <button
                class="article-open-btn"
                type="button"
                @click="openArticleInNewTab"
              >
                Original öffnen
              </button>
              <span class="preview-state">Interne Reader-Ansicht aktiv</span>
            </div>
            <div class="article-preview-meta">
              <span class="source-badge">{{
                getFeedBadge(getItemFeed(previewItem))
              }}</span>
              <div class="article-preview-source-copy">
                <a
                  class="article-preview-source-name"
                  :href="getItemFeed(previewItem)?.url || '#'"
                  target="_blank"
                  rel="noopener noreferrer"
                  :title="getItemFeed(previewItem)?.url || undefined"
                  >{{ getFeedLabel(getItemFeed(previewItem)) }}</a
                >
                <span class="article-preview-source-detail">
                  {{ formatRelativeTime(previewItem.published_at) }}
                  <template v-if="getFeedHost(getItemFeed(previewItem))">
                    · {{ getFeedHost(getItemFeed(previewItem)) }}
                  </template>
                </span>
              </div>
            </div>
          </div>
          <button
            class="preview-close"
            type="button"
            @click="closeArticlePreview"
            aria-label="Vorschau schliessen"
          >
            ✕
          </button>
        </div>

        <p v-if="previewReader?.error" class="article-preview-notice panel">
          {{ previewReader.error }}
        </p>

        <div class="article-reader-shell">
          <div v-if="previewLoading" class="article-reader-loading panel">
            Lade Artikeltext...
          </div>
          <div class="article-reader-content" v-html="previewBodyHtml" />
        </div>
      </div>
    </div>
  </main>

  <div
    v-if="showCookieNotice"
    class="cookie-banner panel"
    role="dialog"
    aria-live="polite"
    aria-label="Cookie- und Datenschutzhinweis"
  >
    <div class="cookie-banner-copy">
      <strong>Cookie- & Datenschutzhinweis</strong>
      <p>
        Diese Website verwendet derzeit keine Tracking- oder Marketing-Cookies.
        Gespeichert werden nur technisch notwendige Einstellungen fuer Theme und
        diesen Hinweis.
      </p>
    </div>
    <div class="cookie-banner-actions">
      <button
        type="button"
        class="cookie-banner-button cookie-banner-button--secondary"
        @click="openCookieDetails"
      >
        Details
      </button>
      <button
        type="button"
        class="cookie-banner-button"
        @click="acceptCookieNotice"
      >
        Verstanden
      </button>
    </div>
  </div>
</template>
