<script setup>
import { computed, onMounted, ref } from "vue";

const apiBase = import.meta.env.VITE_API_BASE || "";

const feeds = ref([]);
const articles = ref([]);
const search = ref("");
const loading = ref(false);
const message = ref("");
const error = ref("");

const form = ref({
  url: "",
  title: "",
});

const hasArticles = computed(() => articles.value.length > 0);

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
}

async function loadArticles() {
  const params = new URLSearchParams({ per_page: "30" });
  if (search.value.trim()) {
    params.set("search", search.value.trim());
  }

  const result = await apiRequest(`/api/articles?${params.toString()}`);
  articles.value = result.data || [];
}

async function bootstrap() {
  loading.value = true;
  error.value = "";
  try {
    await Promise.all([loadFeeds(), loadArticles()]);
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
  } catch (e) {
    error.value =
      e instanceof Error ? e.message : "Feed konnte nicht gespeichert werden.";
  } finally {
    loading.value = false;
  }
}

async function fetchFeed(feedId) {
  loading.value = true;
  error.value = "";
  message.value = "";

  try {
    const result = await apiRequest(`/api/admin/feeds/${feedId}/fetch`, {
      method: "POST",
    });

    message.value = result.output || "Feed erfolgreich aktualisiert.";

    await Promise.all([loadFeeds(), loadArticles()]);
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Fetch fehlgeschlagen.";
  } finally {
    loading.value = false;
  }
}

onMounted(bootstrap);
</script>

<template>
  <main class="page">
    <section class="hero">
      <p class="overline">Elmo Scanner</p>
      <h1>RSS Reader MVP</h1>
      <p class="lead">
        Feeds verwalten, Artikel durchsuchen und Fetch pro Feed manuell
        triggern.
      </p>
    </section>

    <section class="panel">
      <h2>Neuen Feed hinzufuegen</h2>
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
        <button :disabled="loading" type="submit">Speichern</button>
      </form>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Feeds</h2>
        <span>{{ feeds.length }} Quellen</span>
      </div>

      <ul class="feed-list">
        <li v-for="feed in feeds" :key="feed.id">
          <div>
            <strong>{{ feed.title || "Ohne Titel" }}</strong>
            <p>{{ feed.url }}</p>
          </div>
          <button :disabled="loading" @click="fetchFeed(feed.id)">
            Jetzt abrufen
          </button>
        </li>
      </ul>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Artikel</h2>
        <input
          v-model="search"
          placeholder="Suche Titel/Zusammenfassung"
          @keyup.enter="loadArticles"
        />
      </div>

      <button class="refresh" :disabled="loading" @click="loadArticles">
        Artikel laden
      </button>

      <ul v-if="hasArticles" class="article-list">
        <li v-for="article in articles" :key="article.id">
          <a :href="article.url" target="_blank" rel="noreferrer">{{
            article.title
          }}</a>
          <p>{{ article.feed?.title || "Unbekannter Feed" }}</p>
        </li>
      </ul>
      <p v-else class="empty">Noch keine Artikel vorhanden.</p>
    </section>

    <p v-if="message" class="message">{{ message }}</p>
    <p v-if="error" class="error">{{ error }}</p>
  </main>
</template>
