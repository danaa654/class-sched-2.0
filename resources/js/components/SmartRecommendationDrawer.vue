<template>
  <Drawer :visible="visible" position="right" :closable="true" @hide="close" class="recommendation-drawer">
    <div class="p-d-flex p-jc-between p-ai-center p-mb-3">
      <h3>Smart Schedule Recommendation</h3>
      <Button icon="pi pi-times" class="p-button-text" @click="close" />
    </div>

    <div class="meta p-mb-4">
      <p><strong>Subject:</strong> {{ subject.name }} <small>({{ subject.code }})</small></p>
      <p><strong>Section:</strong> {{ section.name }}</p>
      <p><strong>Major:</strong> {{ major || '—' }}</p>
      <p><strong>Academic Year:</strong> {{ academicYear || '—' }} &nbsp; <strong>Semester:</strong> {{ semester || '—' }}</p>
    </div>

    <Divider />

    <section aria-labelledby="faculty-recs">
      <h4 id="faculty-recs">Faculty Recommendations</h4>
      <div class="p-grid">
        <div class="p-col-12 p-md-6" v-for="(f, idx) in faculty" :key="'f'+idx">
          <Card>
            <div class="p-d-flex p-jc-between p-ai-center">
              <div>
                <h5>{{ f.name }}</h5>
                <small>{{ f.department }} — {{ f.college }}</small>
                <div class="p-mt-2">
                  <Rating :value="f.stars || 5" :readonly="true" :cancel="false" />
                  <Badge :value="f.label || badgeLabel(f.score)" :severity="labelSeverity(f.score)" class="p-ml-2" />
                </div>
                <p class="p-mt-2"><strong>Current Load:</strong> {{ f.current_load }} / {{ f.max_load }} Units</p>
                <ul>
                  <li v-for="(r, i) in f.reasons" :key="i">✓ {{ r }}</li>
                </ul>
              </div>

              <div style="width:160px" class="p-text-right">
                <ProgressBar :value="Math.round(f.score) || 0" :style="{ height: '10px' }" />
                <div class="p-mt-2"><strong>{{ Math.round(f.score) || 0 }}%</strong></div>
                <div class="p-mt-2">
                  <Button label="Apply" icon="pi pi-check" class="p-button-success" @click="applyFaculty(f)" />
                </div>
              </div>
            </div>
          </Card>
        </div>
      </div>
      <p v-if="faculty.length === 0" class="p-mt-2">No faculty recommendations available.</p>
    </section>

    <Divider />

    <section aria-labelledby="room-recs">
      <h4 id="room-recs">Room Recommendations</h4>
      <div class="p-grid">
        <div class="p-col-12 p-md-6" v-for="(r, idx) in rooms" :key="'r'+idx">
          <Card>
            <div class="p-d-flex p-jc-between p-ai-center">
              <div>
                <h5>{{ r.name }}</h5>
                <small>{{ r.type }} — Capacity: {{ r.capacity }}</small>
                <p class="p-mt-2"><strong>Status:</strong> {{ r.current_status || 'Unknown' }}</p>
                <ul class="p-mt-2">
                  <li v-for="(reason, i) in r.reasons" :key="i">✓ {{ reason }}</li>
                </ul>
              </div>

              <div style="width:160px" class="p-text-right">
                <ProgressBar :value="Math.round(r.score) || 0" />
                <div class="p-mt-2"><strong>{{ Math.round(r.score) || 0 }}%</strong></div>
                <Button label="Apply" class="p-button-warning p-mt-2" icon="pi pi-check" @click="applyRoom(r)" />
              </div>
            </div>
          </Card>
        </div>
      </div>
      <p v-if="rooms.length === 0" class="p-mt-2">No room recommendations available.</p>
    </section>

    <Divider />

    <section aria-labelledby="time-recs">
      <h4 id="time-recs">Time Recommendations</h4>
      <div class="p-grid">
        <div class="p-col-12 p-md-6" v-for="(t, idx) in times" :key="'t'+idx">
          <Card>
            <div class="p-d-flex p-jc-between p-ai-center">
              <div>
                <h5>{{ t.day }}</h5>
                <div>{{ t.start }} - {{ t.end }}</div>
                <ul class="p-mt-2">
                  <li v-for="(reason, i) in t.reasons" :key="i">✓ {{ reason }}</li>
                </ul>
              </div>

              <div style="width:160px" class="p-text-right">
                <ProgressBar :value="Math.round(t.score) || 0" />
                <div class="p-mt-2"><strong>{{ Math.round(t.score) || 0 }}%</strong></div>
                <Button label="Apply" class="p-button-info p-mt-2" icon="pi pi-check" @click="applyTime(t)" />
              </div>
            </div>
          </Card>
        </div>
      </div>
      <p v-if="times.length === 0" class="p-mt-2">No time recommendations available.</p>
    </section>

    <Divider />

    <section aria-labelledby="combined-recs">
      <h4 id="combined-recs">Combined Recommendations</h4>
      <div v-for="(c, idx) in combined" :key="'c'+idx" class="p-mb-3">
        <Card>
          <div class="p-d-flex p-jc-between p-ai-center">
            <div>
              <h5>Recommendation #{{ idx + 1 }}</h5>
              <p><strong>Faculty:</strong> {{ c.faculty.name }} | <strong>Room:</strong> {{ c.room.name }}</p>
              <p><strong>Schedule:</strong> {{ c.time.day }} {{ c.time.start }} - {{ c.time.end }}</p>
              <p v-if="c.conflict" class="p-text-danger"><strong>Conflict:</strong> {{ c.conflict }}</p>
              <ul class="p-mt-2">
                <li v-for="(r, i) in c.reasons || []" :key="i">✓ {{ r }}</li>
              </ul>
            </div>

            <div style="width:160px" class="p-text-right">
              <ProgressBar :value="Math.round(c.score) || 0" />
              <div class="p-mt-2"><strong>{{ Math.round(c.score) || 0 }}%</strong></div>
              <Button label="Apply" class="p-button-success p-mt-2" icon="pi pi-check" @click="applyCombined(c)" :disabled="!!c.conflict" />
            </div>
          </div>
        </Card>
      </div>
      <p v-if="combined.length === 0" class="p-mt-2">No combined recommendations available.</p>
    </section>

  </Drawer>
</template>

<script>
import Drawer from 'primevue/drawer';
import Card from 'primevue/card';
import Button from 'primevue/button';
import ProgressBar from 'primevue/progressbar';
import Badge from 'primevue/badge';
import Rating from 'primevue/rating';
import Divider from 'primevue/divider';

export default {
  name: 'SmartRecommendationDrawer',
  components: { Drawer, Card, Button, ProgressBar, Badge, Rating, Divider },
  props: {
    visible: { type: Boolean, required: true },
    subject: { type: Object, required: true },
    section: { type: Object, required: true },
    major: { type: String, default: '' },
    academicYear: { type: String, default: '' },
    semester: { type: String, default: '' }
  },
  data() {
    return { faculty: [], rooms: [], times: [], combined: [], loading: false };
  },
  watch: {
    visible(v) {
      if (v) this.fetchRecommendations();
    }
  },
  methods: {
    labelSeverity(score) {
      if (!score && score !== 0) return 'info';
      if (score >= 85) return 'success';
      if (score >= 60) return 'warning';
      return 'danger';
    },
    badgeLabel(score) {
      if (score >= 95) return 'Best Match';
      if (score >= 75) return 'Good Match';
      return 'Alternative';
    },
    close() {
      this.$emit('update:visible', false);
    },
    async fetchRecommendations() {
      this.loading = true;
      try {
        const params = new URLSearchParams({
          subject_id: this.subject.id,
          section_id: this.section.id,
          major: this.major || '',
          academic_year: this.academicYear || '',
          semester: this.semester || ''
        });
        const res = await fetch(`/api/recommendations?${params.toString()}`, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('Failed to fetch recommendations');
        const data = await res.json();
        this.faculty = data.faculty || [];
        this.rooms = data.rooms || [];
        this.times = data.times || [];
        this.combined = data.combined || [];
      } catch (err) {
        console.error(err);
        this.$emit('error', err.message || 'Failed to load recommendations');
      } finally {
        this.loading = false;
      }
    },
    applyFaculty(f) { this.$emit('apply-recommendation', { type: 'faculty', payload: f }); },
    applyRoom(r) { this.$emit('apply-recommendation', { type: 'room', payload: r }); },
    applyTime(t) { this.$emit('apply-recommendation', { type: 'time', payload: t }); },
    applyCombined(c) {
      // Make sure no conflicts
      if (c.conflict) return;
      this.$emit('apply-recommendation', { type: 'combined', payload: c });
      this.close();
    }
  }
};
</script>

<style scoped>
.meta p { margin: 0.15rem 0; }
.recommendation-drawer { width: 720px; }
</style>
