import './bootstrap';

import Alpine from 'alpinejs';
import Swal from 'sweetalert2';
import toastr from 'toastr';

window.Alpine = Alpine;
window.Swal = Swal;
window.toastr = toastr;

toastr.options = {
    closeButton: true,
    newestOnTop: true,
    positionClass: 'toast-top-right',
    progressBar: true,
    timeOut: 4500,
};

Alpine.store('ui', {
    normalizeGender(gender) {
        return ['female', 'Perempuan'].includes(gender) ? 'female' : 'male';
    },

    genderCardClass(gender) {
        return this.normalizeGender(gender) === 'female'
            ? 'border-rose-200 bg-gradient-to-br from-rose-50 to-fuchsia-50/70'
            : 'border-sky-200 bg-gradient-to-br from-sky-50 to-indigo-50/70';
    },

    genderBadgeClass(gender) {
        return this.normalizeGender(gender) === 'female'
            ? 'bg-rose-100 text-rose-800 ring-1 ring-rose-200'
            : 'bg-sky-100 text-sky-800 ring-1 ring-sky-200';
    },

    genderNumberClass(gender) {
        return this.normalizeGender(gender) === 'female'
            ? 'bg-rose-600 text-white shadow-rose-200'
            : 'bg-indigo-600 text-white shadow-indigo-200';
    },

    genderIcon(gender) {
        return this.normalizeGender(gender) === 'female' ? '👩' : '👨';
    },

    tvGenderCardClass(gender) {
        return this.normalizeGender(gender) === 'female'
            ? 'border-rose-300/40 bg-rose-950/35'
            : 'border-sky-300/40 bg-indigo-950/35';
    },

    tvGenderBadgeClass(gender) {
        return this.normalizeGender(gender) === 'female'
            ? 'bg-rose-300/20 text-rose-100 ring-1 ring-rose-200/30'
            : 'bg-sky-300/20 text-sky-100 ring-1 ring-sky-200/30';
    },
});

const beginRealtimeRefresh = (component) => {
    if (component.isRefreshing) {
        component.refreshPending = true;

        return false;
    }

    component.isRefreshing = true;

    return true;
};

const realtimeDriver = () => document.documentElement.dataset.realtimeDriver ?? 'reverb';
const pollingIntervalMs = () => Math.max(
    2000,
    Number(document.documentElement.dataset.pollingIntervalMs ?? 3000),
);

const reportRefreshError = (component, message) => {
    const now = Date.now();

    if ((component.lastRefreshErrorAt ?? 0) + 30000 > now) {
        return;
    }

    component.lastRefreshErrorAt = now;
    toastr.error(message);
};

const finishRealtimeRefresh = (component, method = 'refresh') => {
    component.isRefreshing = false;

    if (! component.refreshPending) {
        return;
    }

    component.refreshPending = false;
    window.queueMicrotask(() => component[method]());
};

const refreshAfterReconnect = (component, method = 'refresh') => {
    if (realtimeDriver() === 'polling') {
        window.clearInterval(component.pollingTimer);
        component.pollingTimer = window.setInterval(() => component[method](), pollingIntervalMs());

        return;
    }

    window.addEventListener('sehat:realtime-connected', () => component[method]());
};

const refreshAfterOperation = (component, method = 'refresh') => {
    window.addEventListener('sehat:operation-completed', () => component[method]());
};

const listenForQueueUpdates = (channel, callback) => {
    [
        '.queue.updated',
        '.participant.registered',
        '.participant.moved-to-eligibility',
        '.participant.moved-to-donation',
        '.participant.donation-completed',
        '.participant.moved-to-health-check',
        '.participant.health-check-completed',
    ].forEach((eventName) => channel?.listen(eventName, callback));
};

Alpine.data('realtimeStatus', () => ({
    state: 'connecting',
    connection: null,

    init() {
        if (realtimeDriver() === 'polling') {
            this.state = 'polling';

            return;
        }

        this.connection = window.Echo?.connector?.pusher?.connection ?? null;

        if (! this.connection) {
            this.state = 'unavailable';

            return;
        }

        this.state = this.connection.state;
        this.connection.bind('state_change', ({ current }) => {
            const previous = this.state;
            this.state = current;

            if (current === 'connected' && previous !== 'connected') {
                window.dispatchEvent(new CustomEvent('sehat:realtime-connected'));
            }
        });
    },

    get label() {
        return {
            connected: 'Realtime aktif',
            connecting: 'Menghubungkan',
            initialized: 'Menghubungkan',
            unavailable: 'Belum tersedia',
            polling: 'Pembaruan berkala',
            disconnected: 'Terputus',
            failed: 'Koneksi gagal',
            unavailable_network: 'Jaringan tidak tersedia',
        }[this.state] ?? 'Menghubungkan';
    },

    get dotClass() {
        return ['connected', 'polling'].includes(this.state) ? 'bg-emerald-500' : 'bg-amber-500';
    },

    get badgeClass() {
        return ['connected', 'polling'].includes(this.state)
            ? 'border-emerald-200 text-emerald-700'
            : 'border-amber-200 text-amber-700';
    },

    get textClass() {
        return this.state === 'connected' ? 'text-emerald-700' : 'text-amber-700';
    },
}));

Alpine.data('liveClock', () => ({
    now: new Date(),
    interval: null,

    init() {
        this.interval = window.setInterval(() => {
            this.now = new Date();
        }, 1000);
    },

    destroy() {
        window.clearInterval(this.interval);
    },

    get time() {
        return new Intl.DateTimeFormat('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }).format(this.now);
    },

    get date() {
        return new Intl.DateTimeFormat('id-ID', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(this.now);
    },
}));

Alpine.data('eventIndex', (initialEvents, dataUrl) => ({
    events: initialEvents,
    isRefreshing: false,
    lastError: null,

    init() {
        window.Echo?.private('events').listen('.event.lifecycle.updated', () => {
            this.refresh();
        });
        refreshAfterReconnect(this);
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        this.lastError = null;

        try {
            const response = await window.fetch(dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error('Data event tidak dapat diperbarui.');
            }

            const payload = await response.json();
            this.events = payload.data;
        } catch (error) {
            this.lastError = error.message;
            toastr.error('Perubahan event akan tersedia setelah halaman dimuat kembali.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },

    formatDate(value) {
        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    },
}));

Alpine.data('servicePostIndex', (initialPosts, dataUrl, eventId) => ({
    posts: initialPosts,
    isRefreshing: false,
    lastError: null,

    init() {
        window.Echo?.private(`events.${eventId}`).listen('.service-post.updated', () => {
            this.refresh();
        });
        refreshAfterReconnect(this);
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        this.lastError = null;

        try {
            const response = await window.fetch(dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error('Data pos pelayanan tidak dapat diperbarui.');
            }

            const payload = await response.json();
            this.posts = payload.data;
        } catch (error) {
            this.lastError = error.message;
            toastr.error('Perubahan pos akan tersedia setelah halaman dimuat kembali.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },
}));

Alpine.data('participantIndex', (initialParticipants, initialQuery, dataUrl, autocompleteUrl) => ({
    participants: initialParticipants,
    query: initialQuery,
    suggestions: [],
    isRefreshing: false,
    lastError: null,

    init() {
        window.Echo?.private('participants').listen('.participant.updated', () => {
            this.refresh();
        });
        refreshAfterReconnect(this);
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        this.lastError = null;

        try {
            const response = await window.fetch(`${dataUrl}?q=${encodeURIComponent(this.query)}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error('Data peserta tidak dapat diperbarui.');
            }

            const payload = await response.json();
            this.participants = payload.data;
        } catch (error) {
            this.lastError = error.message;
            toastr.error('Pencarian peserta tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },

    async autocomplete() {
        if (this.query.trim().length < 2) {
            this.suggestions = [];

            return;
        }

        try {
            const response = await window.fetch(`${autocompleteUrl}?q=${encodeURIComponent(this.query)}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.ok) {
                this.suggestions = (await response.json()).data;
            }
        } catch {
            this.suggestions = [];
        }
    },

    selectSuggestion(participant) {
        this.query = participant.name;
        this.suggestions = [];
        this.refresh();
    },
}));

Alpine.data('checkInDesk', (
    initialTickets,
    dataUrl,
    autocompleteUrl,
    quickParticipantUrl,
    eventId,
    initialWorkflow,
    initialServices,
) => ({
    tickets: initialTickets,
    query: '',
    suggestions: [],
    selected: null,
    selectedServices: initialServices,
    defaultServices: [...initialServices],
    availableServices: initialWorkflow.available_services ?? {},
    workflowErrors: initialWorkflow.errors ?? [],
    eventActive: initialWorkflow.event_active ?? false,
    eventStatusLabel: initialWorkflow.event_status_label ?? '',
    submitting: false,
    isRefreshing: false,
    searchLoading: false,
    searchCompleted: false,
    activeSuggestionIndex: -1,
    searchController: null,
    quickOpen: false,
    quickSubmitting: false,
    pollingTimer: null,
    quickErrors: {},
    quickParticipant: {
        name: '',
        phone: '',
        gender: 'male',
    },

    init() {
        const channel = window.Echo?.channel(`events.${eventId}`);
        channel?.listen('.participant.checked-in', () => {
            this.refreshTickets();
        });
        channel?.listen('.service-post.updated', () => this.refreshTickets());
        channel?.listen('.event.lifecycle.updated', () => this.refreshTickets());
        listenForQueueUpdates(channel, () => this.refreshTickets());
        refreshAfterReconnect(this, 'refreshTickets');
        window.addEventListener('sehat:operation-completed', ({ detail }) => {
            if (detail?.kind === 'check-in') {
                this.clear();
            }

            this.refreshTickets();
        });
        window.addEventListener('sehat:operation-failed', () => {
            this.submitting = false;
        });
        this.$nextTick(() => document.getElementById('participant-search')?.focus());
    },

    destroy() {
        window.clearInterval(this.pollingTimer);
    },

    get hasAvailableService() {
        return Object.values(this.availableServices).some(Boolean);
    },

    get workflowMessages() {
        if (this.eventActive) {
            return this.workflowErrors;
        }

        return [
            `Event berstatus ${this.eventStatusLabel || 'tidak aktif'}. Registrasi ditutup.`,
            ...this.workflowErrors,
        ];
    },

    get workflowHeading() {
        if (! this.eventActive) {
            return 'Event tidak aktif';
        }

        return this.hasAvailableService
            ? 'Sebagian layanan belum tersedia'
            : 'Check-In belum dapat digunakan';
    },

    serviceAvailable(service) {
        return this.availableServices[service] === true;
    },

    applyWorkflow(workflow) {
        this.availableServices = workflow.available_services ?? {};
        this.workflowErrors = workflow.errors ?? [];
        this.eventActive = workflow.event_active ?? false;
        this.eventStatusLabel = workflow.event_status_label ?? '';
        this.selectedServices = this.selectedServices.filter(
            (service) => this.serviceAvailable(service),
        );

        if (this.eventActive && this.selectedServices.length === 0) {
            const firstAvailableService = Object.keys(this.availableServices)
                .find((service) => this.serviceAvailable(service));

            if (firstAvailableService) {
                this.selectedServices = [firstAvailableService];
            }
        }

        if (! this.eventActive || ! this.hasAvailableService) {
            this.submitting = false;
        }
    },

    async search() {
        this.selected = null;
        this.searchCompleted = false;
        this.activeSuggestionIndex = -1;

        if (this.query.trim().length < 2) {
            this.searchController?.abort();
            this.suggestions = [];
            this.searchLoading = false;

            return;
        }

        this.searchController?.abort();
        const controller = new AbortController();
        this.searchController = controller;
        this.searchLoading = true;

        try {
            const response = await window.fetch(`${autocompleteUrl}?q=${encodeURIComponent(this.query)}`, {
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            this.suggestions = response.ok ? (await response.json()).data : [];
            this.searchCompleted = true;
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }

            this.suggestions = [];
            this.searchCompleted = true;
            toastr.error('Pencarian peserta gagal. Periksa koneksi dan coba lagi.');
        } finally {
            if (this.searchController === controller) {
                this.searchLoading = false;
            }
        }
    },

    select(participant) {
        if (participant.registration?.is_available === false) {
            toastr.info(participant.registration.is_finished
                ? 'Peserta ini sudah selesai dan tetap tersedia sebagai riwayat.'
                : 'Peserta ini sudah terdaftar pada Event aktif.');

            return;
        }

        this.selected = participant;
        this.query = participant.name;
        this.suggestions = [];
        this.searchCompleted = false;
        this.activeSuggestionIndex = -1;
    },

    moveSuggestion(direction) {
        const availableSuggestions = this.suggestions.filter(
            (participant) => participant.registration?.is_available !== false,
        );

        if (availableSuggestions.length === 0) {
            return;
        }

        const currentParticipant = this.suggestions[this.activeSuggestionIndex];
        const currentIndex = availableSuggestions.indexOf(currentParticipant);
        const nextIndex = (currentIndex + direction + availableSuggestions.length) % availableSuggestions.length;
        this.activeSuggestionIndex = this.suggestions.indexOf(availableSuggestions[nextIndex]);
    },

    chooseActiveSuggestion() {
        if (this.activeSuggestionIndex >= 0) {
            const participant = this.suggestions[this.activeSuggestionIndex];

            if (participant?.registration?.is_available !== false) {
                this.select(participant);
            }
        }
    },

    openQuickParticipant() {
        this.quickErrors = {};
        this.quickParticipant = {
            name: this.query.trim(),
            phone: '',
            gender: 'male',
        };
        this.quickOpen = true;
        this.$nextTick(() => {
            window.setTimeout(() => document.getElementById('quick-participant-name')?.focus(), 0);
        });
    },

    closeQuickParticipant() {
        if (this.quickSubmitting) {
            return;
        }

        this.quickOpen = false;
        this.quickErrors = {};
        this.$nextTick(() => document.getElementById('participant-search')?.focus());
    },

    async createQuickParticipant() {
        if (this.quickSubmitting) {
            return;
        }

        this.quickSubmitting = true;
        this.quickErrors = {};

        try {
            const response = await window.fetch(quickParticipantUrl, {
                method: 'POST',
                body: JSON.stringify(this.quickParticipant),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });
            const payload = await response.json().catch(() => ({}));

            if (! response.ok) {
                this.quickErrors = payload.errors ?? {};
                throw new Error(
                    Object.values(this.quickErrors).flat()[0]
                    ?? payload.message
                    ?? 'Peserta tidak dapat ditambahkan.',
                );
            }

            this.select(payload.data);
            this.quickOpen = false;
            toastr.success(payload.message);
            this.$nextTick(() => document.getElementById('check-in-submit')?.focus());
        } catch (error) {
            toastr.error(error instanceof Error ? error.message : 'Peserta tidak dapat ditambahkan.');
        } finally {
            this.quickSubmitting = false;
        }
    },

    clear() {
        this.selected = null;
        this.query = '';
        this.suggestions = [];
        this.searchCompleted = false;
        this.activeSuggestionIndex = -1;
        this.selectedServices = this.defaultServices.filter(
            (service) => this.serviceAvailable(service),
        );
        this.submitting = false;
        this.$nextTick(() => document.getElementById('participant-search')?.focus());
    },

    async refreshTickets() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error();
            }

            const payload = await response.json();
            this.tickets = payload.data;
            this.applyWorkflow(payload.meta.workflow);
        } catch {
            reportRefreshError(this, 'Status registrasi realtime tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this, 'refreshTickets');
        }
    },
}));

Alpine.data('healthQueue', (initialTickets, dataUrl, eventId) => ({
    tickets: initialTickets,
    isRefreshing: false,
    pollingTimer: null,

    init() {
        const channel = window.Echo?.private(`events.${eventId}`);
        channel?.listen('.health.queue.updated', () => this.refresh());
        channel?.listen('.participant.checked-in', () => this.refresh());
        listenForQueueUpdates(channel, () => this.refresh());
        refreshAfterReconnect(this);
        refreshAfterOperation(this);
        window.addEventListener('sehat:operation-completed', ({ detail }) => {
            if (detail?.kind === 'screening') {
                this.closeDecision();
            }
        });
    },

    destroy() {
        window.clearInterval(this.pollingTimer);
    },

    get waitingTickets() {
        return this.tickets.filter((ticket) => ['waiting', 'skipped'].includes(ticket.status));
    },

    get callingTickets() {
        return this.tickets.filter((ticket) => ticket.status === 'calling');
    },

    get servingTickets() {
        return this.tickets.filter((ticket) => ticket.status === 'serving');
    },

    get activeTickets() {
        return this.tickets.filter((ticket) => ticket.status !== 'finished');
    },

    get finishedTickets() {
        return this.tickets.filter((ticket) => ticket.status === 'finished');
    },

    statusClass(status) {
        return {
            waiting: 'bg-slate-100 text-slate-600',
            skipped: 'bg-rose-50 text-rose-700',
            calling: 'bg-amber-100 text-amber-800',
            serving: 'bg-emerald-100 text-emerald-800',
        }[status] ?? 'bg-slate-100 text-slate-600';
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error();
            }

            this.tickets = (await response.json()).data;
        } catch {
            reportRefreshError(this, 'Antrean kesehatan realtime tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },
}));

Alpine.data('screeningDesk', (initialParticipants, dataUrl, eventId) => ({
    participants: initialParticipants,
    selected: null,
    decision: null,
    reason: '',
    isRefreshing: false,
    pollingTimer: null,
    lastFocusedElement: null,

    init() {
        const channel = window.Echo?.private(`events.${eventId}`);
        channel?.listen('.health.queue.updated', () => this.refresh());
        channel?.listen('.screening.updated', () => this.refresh());
        listenForQueueUpdates(channel, () => this.refresh());
        refreshAfterReconnect(this);
        refreshAfterOperation(this);
    },

    destroy() {
        window.clearInterval(this.pollingTimer);
    },

    get waitingParticipants() {
        return this.participants.filter((participant) => participant.status === 'waiting_screening');
    },

    get decidedParticipants() {
        return this.participants.filter((participant) => participant.screening !== null);
    },

    get eligibleParticipants() {
        return this.decidedParticipants.filter((participant) => participant.screening.result === 'eligible');
    },

    get notEligibleParticipants() {
        return this.decidedParticipants.filter((participant) => participant.screening.result === 'not_eligible');
    },

    choose(participant, decision) {
        this.lastFocusedElement = document.activeElement;
        this.selected = participant;
        this.decision = decision;
        this.reason = '';
        this.$nextTick(() => this.$refs.decisionDialog?.focus());
    },

    closeDecision() {
        this.selected = null;
        this.decision = null;
        this.reason = '';
        this.$nextTick(() => this.lastFocusedElement?.focus());
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error();
            }

            this.participants = (await response.json()).data;
        } catch {
            reportRefreshError(this, 'Data screening realtime tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },
}));

Alpine.data('donorQueue', (initialTickets, dataUrl, eventId) => ({
    tickets: initialTickets,
    isRefreshing: false,
    pollingTimer: null,

    init() {
        const channel = window.Echo?.private(`events.${eventId}`);
        channel?.listen('.donor.queue.updated', () => this.refresh());
        channel?.listen('.screening.updated', () => this.refresh());
        listenForQueueUpdates(channel, () => this.refresh());
        refreshAfterReconnect(this);
        refreshAfterOperation(this);
    },

    destroy() {
        window.clearInterval(this.pollingTimer);
    },

    get waitingTickets() {
        return this.tickets.filter((ticket) => ['waiting', 'skipped'].includes(ticket.status));
    },

    get callingTickets() {
        return this.tickets.filter((ticket) => ticket.status === 'calling');
    },

    get servingTickets() {
        return this.tickets.filter((ticket) => ticket.status === 'serving');
    },

    get finishedTickets() {
        return this.tickets.filter((ticket) => ticket.status === 'finished');
    },

    ticketsFor(queueType) {
        return this.tickets.filter((ticket) => ticket.queue_type === queueType && ticket.status !== 'finished');
    },

    statusClass(status) {
        return {
            waiting: 'bg-slate-100 text-slate-600',
            skipped: 'bg-rose-50 text-rose-700',
            calling: 'bg-amber-100 text-amber-800',
            serving: 'bg-emerald-100 text-emerald-800',
        }[status] ?? 'bg-slate-100 text-slate-600';
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error();
            }

            this.tickets = (await response.json()).data;
        } catch {
            reportRefreshError(this, 'Antrean donor realtime tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },
}));

Alpine.data('waitingQueue', (initialQueue, dataUrl, eventId, capacityUpdateUrl = null, canUpdateCapacity = false) => ({
    snapshot: initialQueue,
    dataUrl,
    eventId,
    capacityUpdateUrl,
    canUpdateCapacity,
    capacityGenders: [
        { key: 'male' },
        { key: 'female' },
    ],
    editableCapacity: {
        male: initialQueue?.donation_capacity?.male?.capacity ?? 1,
        female: initialQueue?.donation_capacity?.female?.capacity ?? 1,
    },
    isRefreshing: false,
    refreshPending: false,
    pollingTimer: null,
    goto: {
        open: false,
        lane: 'global',
        search: '',
        selectedTicketId: null,
    },
    lanes: [{
        value: 'global',
        label: 'Global',
        cardClass: 'border-indigo-200',
        headerClass: 'bg-gradient-to-r from-indigo-700 to-sky-600',
        currentClass: 'border-sky-100 bg-gradient-to-br from-sky-50 to-indigo-50/70',
        numberClass: 'text-indigo-700',
        nextButtonClass: 'bg-indigo-600 hover:bg-indigo-700',
    }],
    init() {
        const channel = window.Echo
            ?.channel(`events.${eventId}`)
            .listen('.service.queue.updated', () => this.refresh());
        listenForQueueUpdates(channel, () => this.refresh());
        refreshAfterReconnect(this);
        window.addEventListener('sehat:operation-completed', () => {
            this.closeGoto();
            this.refresh();
        });
    },

    destroy() {
        window.clearInterval(this.pollingTimer);
    },

    get tickets() {
        return this.snapshot?.queue?.tickets ?? [];
    },

    get activePositions() {
        return this.snapshot?.queue?.positions ?? [];
    },

    get capacity() {
        return this.snapshot?.donation_capacity ?? null;
    },

    get healthParticipants() {
        return this.snapshot?.stages?.health_check ?? [];
    },

    get eligibilityParticipants() {
        return this.snapshot?.stages?.waiting_screening ?? [];
    },

    get donatingParticipants() {
        return this.snapshot?.stages?.donating ?? [];
    },

    get finishedParticipants() {
        return this.snapshot?.stages?.finished ?? [];
    },

    get currentParticipants() {
        return this.activePositions.filter((participant) => participant.call?.is_active);
    },

    get primaryParticipant() {
        return this.activePositions.find((participant) => participant.status === 'calling')
            ?? this.activePositions.find((participant) => participant.status === 'health_check')
            ?? this.activePositions.find((participant) => participant.status === 'waiting_screening')
            ?? this.activePositions.find((participant) => participant.status === 'donating')
            ?? null;
    },

    get waitingTickets() {
        return this.tickets
            .filter((ticket) => ticket.status === 'waiting')
            .sort((first, second) => this.compareRegistrationOrder(first, second));
    },

    get upcomingTickets() {
        return this.waitingTickets.slice(0, 3);
    },

    get currentTicket() {
        return this.tickets.find((ticket) => ticket.status === 'calling') ?? null;
    },

    get nextUrl() {
        return this.snapshot?.queue?.next_url ?? '';
    },

    get canCallNext() {
        return this.currentTicket === null && this.waitingTickets.length > 0;
    },

    get gotoTickets() {
        return this.tickets
            .filter((ticket) => ['waiting', 'skipped'].includes(ticket.status))
            .sort((first, second) => this.compareRegistrationOrder(first, second));
    },

    ticketsFor() {
        return this.tickets;
    },

    waitingTicketsFor() {
        return this.waitingTickets;
    },

    gotoTicketsFor() {
        return this.gotoTickets;
    },

    currentTicketFor() {
        return this.currentTicket;
    },

    nextTicketFor() {
        if (! this.canCallNext) {
            return null;
        }

        return { urls: { call: this.nextUrl } };
    },

    skippableTicketFor() {
        return this.currentTicket;
    },

    laneLabel() {
        return 'Global';
    },

    compareRegistrationOrder(first, second) {
        return (first.registration_order ?? Number.MAX_SAFE_INTEGER) - (second.registration_order ?? Number.MAX_SAFE_INTEGER)
            || first.id - second.id;
    },

    ensureNext() {
        if (this.canCallNext) {
            return true;
        }

        toastr.error(this.currentTicket
            ? 'Selesaikan atau Skip peserta yang sedang dipanggil terlebih dahulu.'
            : 'Belum ada peserta menunggu yang dapat dipanggil.');

        return false;
    },

    ensureSkip() {
        if (this.currentTicket) {
            return true;
        }

        toastr.error('Belum ada peserta yang sedang dipanggil.');

        return false;
    },

    openGoto() {
        if (this.currentTicket) {
            toastr.error('Selesaikan atau Skip peserta yang sedang dipanggil terlebih dahulu.');

            return;
        }

        this.goto = {
            open: true,
            lane: 'global',
            search: '',
            selectedTicketId: null,
        };
    },

    closeGoto() {
        this.goto.open = false;
        this.goto.search = '';
        this.goto.selectedTicketId = null;
    },

    gotoCandidates() {
        const search = this.goto.search.trim().toLocaleLowerCase();

        return this.gotoTickets.filter((ticket) => {
            if (! search) {
                return true;
            }

            return [this.displayNumber(ticket), ticket.participant.name]
                .filter(Boolean)
                .some((value) => value.toLocaleLowerCase().includes(search));
        });
    },

    selectedGotoTicket() {
        return this.gotoTickets
            .find((ticket) => ticket.id === this.goto.selectedTicketId)
            ?? null;
    },

    selectGotoTicket(ticket) {
        this.goto.selectedTicketId = ticket.id;
    },

    prepareGoto(event) {
        const ticket = this.selectedGotoTicket();

        if (! ticket) {
            toastr.error('Pilih peserta aktif terlebih dahulu.');

            return false;
        }

        event.currentTarget.action = ticket.urls.goto;

        return true;
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(this.dataUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (! response.ok) {
                throw new Error();
            }

            this.snapshot = await response.json();
            this.editableCapacity = {
                male: this.capacity?.male?.capacity ?? this.editableCapacity.male,
                female: this.capacity?.female?.capacity ?? this.editableCapacity.female,
            };
        } catch {
            reportRefreshError(this, 'Data Area Tunggu tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },

    displayNumber(ticket) {
        return ticket?.display_number ?? ticket?.number ?? null;
    },

    capacityFor(gender) {
        return this.capacity?.[gender] ?? {
            label: gender === 'male' ? 'Laki-laki' : 'Perempuan',
            capacity: 0,
            active: 0,
            available: 0,
            is_full: true,
        };
    },

    adjustCapacity(gender, amount) {
        const current = Number(this.editableCapacity[gender] ?? 1);
        this.editableCapacity[gender] = Math.max(1, Math.min(50, current + amount));
    },
}));

Alpine.data('operationalStage', (initialParticipants, dataUrl, eventId, initialCapacity = null, capacityUpdateUrl = null, canUpdateCapacity = false) => ({
    participants: initialParticipants,
    dataUrl,
    eventId,
    capacity: initialCapacity,
    capacityUpdateUrl,
    canUpdateCapacity,
    capacityGenders: [
        { key: 'male' },
        { key: 'female' },
    ],
    editableCapacity: {
        male: initialCapacity?.male?.capacity ?? 1,
        female: initialCapacity?.female?.capacity ?? 1,
    },
    isRefreshing: false,
    refreshPending: false,
    pollingTimer: null,

    init() {
        const channel = window.Echo
            ?.channel(`events.${eventId}`)
            .listen('.service.queue.updated', () => this.refresh());
        listenForQueueUpdates(channel, () => this.refresh());
        refreshAfterReconnect(this);
        refreshAfterOperation(this);
    },

    destroy() {
        window.clearInterval(this.pollingTimer);
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(this.dataUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (! response.ok) {
                throw new Error();
            }

            const payload = await response.json();
            this.participants = payload.data;
            this.capacity = payload.meta?.donation_capacity ?? this.capacity;
            this.editableCapacity = {
                male: this.capacity?.male?.capacity ?? this.editableCapacity.male,
                female: this.capacity?.female?.capacity ?? this.editableCapacity.female,
            };
        } catch {
            reportRefreshError(this, 'Data area operasional tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },

    formatDate(value) {
        return value
            ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
            : '-';
    },

    capacityFor(gender) {
        return this.capacity?.[gender] ?? {
            label: gender === 'male' ? 'Laki-laki' : 'Perempuan',
            capacity: 0,
            active: 0,
            available: 0,
            is_full: true,
        };
    },

    participantsForGender(gender) {
        return this.participants.filter((participant) => participant.participant.gender === gender);
    },

    adjustCapacity(gender, amount) {
        const current = Number(this.editableCapacity[gender] ?? 1);
        this.editableCapacity[gender] = Math.max(1, Math.min(50, current + amount));
    },
}));

Alpine.data('dashboard', (initialSnapshot, dataUrl) => ({
    snapshot: initialSnapshot,
    dataUrl,
    activeChannel: null,
    activeEventId: initialSnapshot.event?.id ?? null,
    subscribedEventId: null,
    isRefreshing: false,
    pollingTimer: null,
    metricCards: [
        { key: 'checked_in', label: 'Peserta hadir', iconClass: 'bg-emerald-50 text-emerald-700', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/>' },
        { key: 'donor', label: 'Donor', iconClass: 'bg-red-50 text-red-700', icon: '<path stroke-linecap="round" d="M12 3c3 4 5 6.5 5 10a5 5 0 0 1-10 0c0-3.5 2-6 5-10Z"/>' },
        { key: 'health_check', label: 'Pemeriksaan kesehatan', iconClass: 'bg-cyan-50 text-cyan-700', icon: '<path stroke-linecap="round" d="M4 12h4l2-5 4 10 2-5h4"/>' },
        { key: 'selected_both', label: 'Donor + kesehatan', iconClass: 'bg-violet-50 text-violet-700', icon: '<path stroke-linecap="round" d="M8 7h8M8 12h8M8 17h8"/>' },
        { key: 'selected_donor_only', label: 'Donor saja', iconClass: 'bg-rose-50 text-rose-700', icon: '<path stroke-linecap="round" d="M12 3c3 4 5 6.5 5 10a5 5 0 0 1-10 0c0-3.5 2-6 5-10Z"/>' },
        { key: 'selected_health_only', label: 'Kesehatan saja', iconClass: 'bg-cyan-50 text-cyan-700', icon: '<path stroke-linecap="round" d="M4 12h4l2-5 4 10 2-5h4"/>' },
        { key: 'waiting', label: 'Menunggu', iconClass: 'bg-amber-50 text-amber-700', icon: '<path stroke-linecap="round" d="M12 6v6l4 2"/>' },
        { key: 'calling', label: 'Sedang dipanggil', iconClass: 'bg-sky-50 text-sky-700', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>' },
        { key: 'health_check_stage', label: 'Cek kesehatan', iconClass: 'bg-cyan-50 text-cyan-700', icon: '<path stroke-linecap="round" d="M4 12h4l2-5 4 10 2-5h4"/>' },
        { key: 'eligibility', label: 'Cek kelayakan donor', iconClass: 'bg-violet-50 text-violet-700', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m-6-6 6 6 6-6M5 5h14"/>' },
        { key: 'donating', label: 'Sedang donor', iconClass: 'bg-rose-50 text-rose-700', icon: '<path stroke-linecap="round" d="M12 3v18M5 12h14"/>' },
        { key: 'finished', label: 'Selesai', iconClass: 'bg-emerald-50 text-emerald-700', icon: '<path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/>' },
    ],

    init() {
        window.Echo?.channel('operational').listen('.event.lifecycle.updated', () => this.refresh());
        this.subscribeToActiveEvent();
        refreshAfterReconnect(this);
    },

    destroy() {
        window.clearInterval(this.pollingTimer);

        if (this.subscribedEventId) {
            window.Echo?.leave(`events.${this.subscribedEventId}`);
        }
    },

    subscribeToActiveEvent() {
        if (! window.Echo || this.subscribedEventId === this.activeEventId) {
            return;
        }

        if (this.subscribedEventId) {
            window.Echo.leave(`events.${this.subscribedEventId}`);
        }

        this.activeChannel = null;
        this.subscribedEventId = null;

        if (! this.activeEventId) {
            return;
        }

        this.activeChannel = window.Echo.channel(`events.${this.activeEventId}`);
        this.subscribedEventId = this.activeEventId;
        [
            '.participant.checked-in',
            '.health.queue.updated',
            '.screening.updated',
            '.donor.queue.updated',
            '.service.queue.updated',
            '.service-post.updated',
            '.queue.updated',
            '.dashboard.updated',
        ].forEach((eventName) => this.activeChannel.listen(eventName, () => this.refresh()));
    },

    formatDate(value) {
        if (! value) {
            return '';
        }

        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    },

    formatActivity(activity) {
        return `${this.formatDate(activity.created_at)} · ${activity.user_name}`;
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(this.dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error();
            }

            this.snapshot = await response.json();

            if (this.snapshot.event?.id !== this.activeEventId) {
                this.activeEventId = this.snapshot.event?.id ?? null;
                this.subscribeToActiveEvent();
            }
        } catch {
            reportRefreshError(this, 'Dashboard realtime tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },
}));

Alpine.data('monitorBoard', (initialSnapshot, dataUrl) => ({
    snapshot: initialSnapshot,
    dataUrl,
    activeEventId: initialSnapshot.event?.id ?? null,
    activeChannel: null,
    subscribedEventId: null,
    isRefreshing: false,
    pollingTimer: null,

    init() {
        window.Echo?.channel('operational').listen('.event.lifecycle.updated', () => this.refresh());
        this.subscribeToActiveEvent();
        refreshAfterReconnect(this);
    },

    destroy() {
        window.clearInterval(this.pollingTimer);

        if (this.subscribedEventId) {
            window.Echo?.leave(`events.${this.subscribedEventId}`);
        }
    },

    subscribeToActiveEvent() {
        if (! window.Echo || this.subscribedEventId === this.activeEventId) {
            return;
        }

        if (this.subscribedEventId) {
            window.Echo.leave(`events.${this.subscribedEventId}`);
        }

        this.activeChannel = null;
        this.subscribedEventId = null;

        if (! this.activeEventId) {
            return;
        }

        this.activeChannel = window.Echo.channel(`events.${this.activeEventId}`);
        this.subscribedEventId = this.activeEventId;
        [
            '.participant.checked-in',
            '.health.queue.updated',
            '.screening.updated',
            '.donor.queue.updated',
            '.service.queue.updated',
            '.service-post.updated',
            '.queue.updated',
            '.tv-monitor.updated',
        ].forEach((eventName) => this.activeChannel.listen(eventName, () => this.refresh()));
    },

    async toggleFullscreen() {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
            }
        } catch {
            toastr.info('Gunakan F11 jika browser tidak mengizinkan mode layar penuh.');
        }
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(this.dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error();
            }

            this.snapshot = await response.json();

            if (this.snapshot.event?.id !== this.activeEventId) {
                this.activeEventId = this.snapshot.event?.id ?? null;
                this.subscribeToActiveEvent();
            }
        } catch {
            reportRefreshError(this, 'Layar monitor tidak dapat diperbarui secara realtime.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },
}));

Alpine.data('reportDashboard', (initialSnapshot, dataUrl, eventId) => ({
    snapshot: initialSnapshot,
    dataUrl,
    eventId,
    isRefreshing: false,
    metricCards: [
        { key: 'checked_in', label: 'Jumlah hadir' },
        { key: 'donor', label: 'Donor' },
        { key: 'health_check', label: 'Pemeriksaan kesehatan' },
        { key: 'selected_donor', label: 'Memilih donor' },
        { key: 'selected_health_check', label: 'Memilih pemeriksaan' },
        { key: 'selected_both', label: 'Memilih keduanya' },
        { key: 'selected_donor_only', label: 'Donor saja' },
        { key: 'selected_health_only', label: 'Kesehatan saja' },
        { key: 'waiting', label: 'Menunggu' },
        { key: 'health_check_stage', label: 'Cek kesehatan' },
        { key: 'eligibility', label: 'Cek kelayakan donor' },
        { key: 'donating', label: 'Sedang donor' },
        { key: 'finished', label: 'Selesai' },
    ],

    init() {
        const channel = window.Echo?.private(`events.${eventId}`);
        [
            '.participant.checked-in',
            '.health.queue.updated',
            '.screening.updated',
            '.donor.queue.updated',
            '.service.queue.updated',
            '.service-post.updated',
            '.queue.updated',
            '.dashboard.updated',
        ].forEach((eventName) => channel?.listen(eventName, () => this.refresh()));
        refreshAfterReconnect(this);
    },

    formatDate(value) {
        return value
            ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
            : '—';
    },

    async refresh() {
        if (! beginRealtimeRefresh(this)) {
            return;
        }

        try {
            const response = await window.fetch(this.dataUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (! response.ok) {
                throw new Error();
            }

            this.snapshot = await response.json();
        } catch {
            reportRefreshError(this, 'Data laporan realtime tidak dapat diperbarui.');
        } finally {
            finishRealtimeRefresh(this);
        }
    },
}));

document.addEventListener('DOMContentLoaded', () => {
    const toast = document.querySelector('[data-toast-message]');

    if (toast?.dataset.toastMessage) {
        toastr.success(toast.dataset.toastMessage);
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (! (form instanceof HTMLFormElement) || ! form.dataset.confirmTitle || form.dataset.confirmed === 'true') {
        return;
    }

    event.preventDefault();

    Swal.fire({
        title: form.dataset.confirmTitle,
        text: form.dataset.confirmMessage ?? 'Tindakan ini tidak dapat dibatalkan.',
        icon: form.dataset.confirmVariant ?? 'warning',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, lanjutkan',
        cancelButtonText: 'Kembali',
        reverseButtons: true,
    }).then((result) => {
        if (result.isConfirmed) {
            form.dataset.confirmed = 'true';
            form.requestSubmit();
        }
    });
});

document.addEventListener('submit', async (event) => {
    const form = event.target;

    if (! (form instanceof HTMLFormElement)
        || ! form.hasAttribute('data-realtime-submit')
        || event.defaultPrevented
    ) {
        return;
    }

    event.preventDefault();

    const buttons = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];
    buttons.forEach((button) => {
        button.disabled = true;
    });

    try {
        const response = await window.fetch(form.action, {
            method: form.method || 'POST',
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = await response.json().catch(() => ({}));

        if (! response.ok) {
            const validationMessage = Object.values(payload.errors ?? {}).flat()[0];
            throw new Error(validationMessage ?? payload.message ?? 'Permintaan tidak dapat diproses.');
        }

        toastr.success(payload.message ?? 'Perubahan berhasil disimpan.');
        window.dispatchEvent(new CustomEvent('sehat:operation-completed', {
            detail: {
                form,
                kind: form.dataset.operationKind
                    ?? (form.action.includes('/check-ins') ? 'check-in' : 'queue'),
                payload,
            },
        }));

        if (payload.redirect_url) {
            window.location.assign(payload.redirect_url);
        }
    } catch (error) {
        toastr.error(error instanceof Error ? error.message : 'Permintaan tidak dapat diproses.');
        window.dispatchEvent(new CustomEvent('sehat:operation-failed', {
            detail: { form },
        }));
    } finally {
        delete form.dataset.confirmed;
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
});

Alpine.start();
