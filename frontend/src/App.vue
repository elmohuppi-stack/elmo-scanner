<script setup>
import { computed, onMounted, ref } from "vue";

const apiBase = import.meta.env.VITE_API_BASE || "";

const feeds = ref([]);
const feedItems = ref({});
const search = ref("");
const loading = ref(false);
const loadingFeedId = ref(null);
const message = ref("");
const error = ref("");
const selectedFeedId = ref(null);
const contentRef = ref(null);
const sidebarTab = ref("feeds");
const activeTag = ref("");

const form = ref({
  url: "",
  title: "",
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

async function apiRequest(path, options = {}) {
  const response = await fetch(`${apiBase}${path}`, {
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {}),
    },
    ...options,
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(body || `Request failed (${response.status})`);
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

function selectFeed(feedId) {
  selectedFeedId.value = feedId;
  sidebarTab.value = "feeds";
  const card = document.getElementById(`feed-card-${feedId}`);

  if (!card) {
    return;
  }

  const contentElement = contentRef.value;
  const isDesktopLayout = window.matchMedia("(min-width: 1021px)").matches;

  if (isDesktopLayout && contentElement instanceof HTMLElement) {
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

onMounted(bootstrap);
</script>

<template>
  <main class="layout">
    <aside class="sidebar panel">
      <div class="sidebar-head">
        <p class="overline">Elmo Scanner</p>
        <h1>Feed Quellen</h1>
        <p class="subtitle">{{ feeds.length }} Quellen</p>
      </div>

      <div class="sidebar-tabs">
        <button
          class="tab-btn"
          :class="{ active: sidebarTab === 'feeds' }"
          @click="setSidebarTab('feeds')"
        >
          Feeds
        </button>
        <button
          class="tab-btn"
          :class="{ active: sidebarTab === 'filters' }"
          @click="setSidebarTab('filters')"
        >
          Filter
        </button>
        <button
          class="tab-btn"
          :class="{ active: sidebarTab === 'new-feed' }"
          @click="setSidebarTab('new-feed')"
        >
          Neuer Feed
        </button>
      </div>

      <section v-if="sidebarTab === 'feeds'" class="sidebar-section">
        <ul class="source-list">
          <li v-for="feed in feeds" :key="feed.id">
            <button
              class="source-item"
              :class="{ active: selectedFeedId === feed.id }"
              @click="selectFeed(feed.id)"
            >
              <strong>{{ feed.title || "Ohne Titel" }}</strong>
              <span>{{ (feedItems[feed.id] || []).length }} Artikel</span>
            </button>
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

    <section ref="contentRef" class="content">
      <header class="content-head panel">
        <div>
          <h2>Feed Cards</h2>
          <p v-if="activeTag" class="active-filter-label">
            Aktiver Tag: {{ activeTag }}
          </p>
        </div>
        <input
          v-model="search"
          placeholder="Suche in allen Feed-Items"
          type="search"
        />
      </header>

      <div v-if="feedCards.length === 0" class="panel empty">
        Keine Feeds vorhanden.
      </div>

      <article
        v-for="entry in feedCards"
        :id="`feed-card-${entry.feed.id}`"
        :key="entry.feed.id"
        class="feed-card panel"
      >
        <header class="feed-card-head">
          <div>
            <h3>{{ entry.feed.title || "Ohne Titel" }}</h3>
            <p>{{ entry.feed.url }}</p>
          </div>
          <div class="feed-meta">
            <span
              >{{ entry.items.length }} von {{ entry.totalItems }} Items</span
            >
            <button
              :disabled="loadingFeedId === entry.feed.id"
              @click="fetchFeed(entry.feed.id)"
            >
              Aktualisieren
            </button>
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
              />

              <div class="item-body">
                <div class="item-top">
                  <span class="item-time">{{
                    formatRelativeTime(item.published_at)
                  }}</span>
                  <ul
                    v-if="
                      Array.isArray(item.categories) &&
                      item.categories.length > 0
                    "
                    class="tag-list"
                  >
                    <li
                      v-for="category in item.categories"
                      :key="`${item.id}-${category}`"
                    >
                      {{ category }}
                    </li>
                  </ul>
                </div>

                <a :href="item.url" target="_blank" rel="noreferrer">{{
                  item.title
                }}</a>
                <p v-if="item.summary">{{ item.summary }}</p>
              </div>
            </div>
          </li>
        </ul>
        <p v-else class="empty-text">Keine Items fuer diesen Feed gefunden.</p>
      </article>

      <p v-if="message" class="message panel">{{ message }}</p>
      <p v-if="error" class="error panel">{{ error }}</p>
    </section>
  </main>
</template>
