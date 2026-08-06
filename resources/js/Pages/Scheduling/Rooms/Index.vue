<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Toolbar from 'primevue/toolbar';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';

const props = defineProps({
    rooms: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ room_search: '' }),
    },
    roomTypes: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
});

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message.
watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
        }
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
        }
    },
);

/* ------------------------------------------------------------------ */
/* Search / list                                                       */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.room_search ?? '');
const loading = ref(false);
let searchDebounce = null;

const reloadRooms = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.rooms'),
        { room_search: search.value, ...extra },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['rooms'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadRooms({ room_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadRooms({ room_page: event.page + 1 });
};

const onRefresh = () => {
    reloadRooms({ room_page: props.rooms.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Room                                                      */
/* ------------------------------------------------------------------ */

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

// College & Department/Program Assignment (Room Management enhancement).
//
// college_id = null  -> "All Colleges" — a shared room every college may use.
// department_id = null -> "All Programs" — open to every program within the
//                          selected college (or, if college is also null,
//                          the room is fully shared with no restriction at all).
//
// This only prepares the data (see Room::department()/college() relations,
// already used elsewhere by the scheduling engine's Room recommendation
// scoring) — no AI/Auto Scheduler logic is touched here.
const ALL_COLLEGES_OPTION = { id: null, name: 'All Colleges' };
const ALL_PROGRAMS_OPTION = { id: null, name: 'All Programs' };

const collegeOptions = [ALL_COLLEGES_OPTION, ...props.colleges];

const addRoomVisible = ref(false);
const editingRoom = ref(null); // null => Add mode, otherwise the Room being edited

const roomForm = useForm({
    room_code: '',
    room_name: '',
    building: '',
    floor: '',
    room_type: null,
    college_id: null,
    department_id: null,
    capacity: 1,
    status: 'Active',
    remarks: '',
});

// Department/Program options depend on the selected College — only
// that College's programs are offered, plus "All Programs".
const departmentOptions = computed(() => {
    if (!roomForm.college_id) {
        return [ALL_PROGRAMS_OPTION];
    }

    return [
        ALL_PROGRAMS_OPTION,
        ...props.departments.filter((department) => department.college_id === roomForm.college_id),
    ];
});

// Selecting "All Colleges" (or switching Colleges) makes the previous
// Department/Program choice invalid, so it's cleared here — but only
// on a user-driven change; openEdit()/openAdd() set both fields
// programmatically and must not have this clear them.
const onCollegeChange = () => {
    roomForm.department_id = null;
};

const openAdd = () => {
    editingRoom.value = null;
    roomForm.reset();
    roomForm.clearErrors();
    addRoomVisible.value = true;
};

const openEdit = (room) => {
    editingRoom.value = room;
    roomForm.clearErrors();
    roomForm.room_code = room.room_code;
    roomForm.room_name = room.room_name;
    roomForm.building = room.building;
    roomForm.floor = room.floor ?? '';
    roomForm.room_type = room.room_type;
    roomForm.college_id = room.college_id ?? null;
    roomForm.department_id = room.department_id ?? null;
    roomForm.capacity = room.capacity;
    roomForm.status = room.status;
    roomForm.remarks = room.remarks ?? '';
    addRoomVisible.value = true;
};

const closeAddRoom = () => {
    addRoomVisible.value = false;
    editingRoom.value = null;
    roomForm.reset();
    roomForm.clearErrors();
};

const onSaveRoom = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            const wasEditing = !!editingRoom.value;
            closeAddRoom();
            Swal.fire({
                title: wasEditing ? 'Room updated' : 'Room saved',
                text: wasEditing
                    ? 'The room was updated successfully.'
                    : 'The room was created successfully.',
                icon: 'success',
                confirmButtonColor: '#16A34A',
            });
            onRefresh();
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    };

    if (editingRoom.value) {
        roomForm.put(route('scheduling.rooms.update', editingRoom.value.id), options);
    } else {
        roomForm.post(route('scheduling.rooms.store'), options);
    }
};

const onDeleteRoom = (room) => {
    Swal.fire({
        title: 'Delete this room?',
        text: `${room.room_code} — ${room.room_name} will be permanently deleted.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, delete it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.delete(route('scheduling.rooms.destroy', room.id), {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
            });
        }
    });
};
</script>

<template>
    <Head title="Rooms" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Rooms</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Rooms</h1>
                <p class="mt-1 text-slate-500">
                    Manage classrooms, laboratories, and other scheduling facilities.
                </p>
            </div>

            <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                <template #content>
                    <!-- Top Toolbar -->
                    <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4 flex-wrap gap-3">
                        <template #start>
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-model="search"
                                    placeholder="Search by code, name, building or type"
                                    class="w-full !pl-9"
                                />
                            </span>
                        </template>
                        <template #end>
                            <div class="flex items-center gap-2">
                                <Button
                                    icon="pi pi-refresh"
                                    severity="secondary"
                                    outlined
                                    :loading="loading"
                                    @click="onRefresh"
                                    aria-label="Refresh"
                                />
                                <Button label="Add Room" icon="pi pi-plus" severity="success" @click="openAdd" />
                            </div>
                        </template>
                    </Toolbar>

                    <!-- Rooms Table -->
                    <DataTable
                        :value="rooms.data"
                        :loading="loading"
                        dataKey="id"
                        class="rounded-xl overflow-hidden"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="rooms.per_page"
                        :totalRecords="rooms.total"
                        :first="(rooms.current_page - 1) * rooms.per_page"
                        @page="onPage"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No rooms found.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    Click "Add Room" to create your first room.
                                </p>
                                <Button
                                    label="Add Room"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="room_code" header="Room Code" style="width: 10rem" />
                        <Column field="room_name" header="Room Name" />
                        <Column field="building" header="Building" style="width: 12rem" />
                        <Column header="Floor" style="width: 7rem">
                            <template #body="{ data }">
                                {{ data.floor || '—' }}
                            </template>
                        </Column>
                        <Column header="Room Type" style="width: 13rem">
                            <template #body="{ data }">
                                {{ data.room_type }}
                            </template>
                        </Column>
                        <Column header="College" style="width: 14rem">
                            <template #body="{ data }">
                                {{ data.college?.name ?? 'All Colleges' }}
                            </template>
                        </Column>
                        <Column header="Department / Program" style="width: 13rem">
                            <template #body="{ data }">
                                {{ data.department?.name ?? 'All Programs' }}
                            </template>
                        </Column>
                        <Column header="Capacity" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.capacity }}
                            </template>
                        </Column>
                        <Column header="Status" style="width: 9rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.status"
                                    :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 9rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-pencil"
                                        text
                                        rounded
                                        severity="secondary"
                                        size="small"
                                        aria-label="Edit"
                                        @click="openEdit(data)"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        aria-label="Delete"
                                        @click="onDeleteRoom(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <!-- Add Room Dialog -->
        <Dialog
            v-model:visible="addRoomVisible"
            modal
            :header="editingRoom ? 'Edit Room' : 'Add Room'"
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            @hide="closeAddRoom"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4" @submit.prevent="onSaveRoom">
                <!-- Room Code -->
                <div class="flex flex-col gap-1">
                    <label for="room_code" class="text-sm font-medium text-slate-700">
                        Room Code <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="room_code"
                        v-model="roomForm.room_code"
                        placeholder="e.g. RM101, LAB2"
                        :invalid="!!roomForm.errors.room_code"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.room_code" class="text-red-500">
                        {{ roomForm.errors.room_code }}
                    </small>
                </div>

                <!-- Room Name -->
                <div class="flex flex-col gap-1">
                    <label for="room_name" class="text-sm font-medium text-slate-700">
                        Room Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="room_name"
                        v-model="roomForm.room_name"
                        placeholder="e.g. Computer Laboratory 2"
                        :invalid="!!roomForm.errors.room_name"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.room_name" class="text-red-500">
                        {{ roomForm.errors.room_name }}
                    </small>
                </div>

                <!-- Building -->
                <div class="flex flex-col gap-1">
                    <label for="building" class="text-sm font-medium text-slate-700">
                        Building <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="building"
                        v-model="roomForm.building"
                        placeholder="e.g. Main Building"
                        :invalid="!!roomForm.errors.building"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.building" class="text-red-500">
                        {{ roomForm.errors.building }}
                    </small>
                </div>

                <!-- Floor -->
                <div class="flex flex-col gap-1">
                    <label for="floor" class="text-sm font-medium text-slate-700">Floor</label>
                    <InputText
                        id="floor"
                        v-model="roomForm.floor"
                        placeholder="e.g. 2nd Floor"
                        :invalid="!!roomForm.errors.floor"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.floor" class="text-red-500">
                        {{ roomForm.errors.floor }}
                    </small>
                </div>

                <!-- Room Type -->
                <div class="flex flex-col gap-1">
                    <label for="room_type" class="text-sm font-medium text-slate-700">
                        Room Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="room_type"
                        v-model="roomForm.room_type"
                        :options="roomTypes"
                        placeholder="Select a room type"
                        :invalid="!!roomForm.errors.room_type"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.room_type" class="text-red-500">
                        {{ roomForm.errors.room_type }}
                    </small>
                </div>

                <!-- College -->
                <div class="flex flex-col gap-1">
                    <label for="college_id" class="text-sm font-medium text-slate-700">College</label>
                    <Select
                        id="college_id"
                        v-model="roomForm.college_id"
                        :options="collegeOptions"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="All Colleges"
                        showClear
                        :invalid="!!roomForm.errors.college_id"
                        class="w-full"
                        @update:modelValue="onCollegeChange"
                    />
                    <small class="text-slate-400">Leave as "All Colleges" for a room shared by everyone.</small>
                    <small v-if="roomForm.errors.college_id" class="text-red-500">
                        {{ roomForm.errors.college_id }}
                    </small>
                </div>

                <!-- Department / Program -->
                <div class="flex flex-col gap-1">
                    <label for="department_id" class="text-sm font-medium text-slate-700">Department / Program</label>
                    <Select
                        id="department_id"
                        v-model="roomForm.department_id"
                        :options="departmentOptions"
                        optionLabel="name"
                        optionValue="id"
                        placeholder="All Programs"
                        showClear
                        :disabled="!roomForm.college_id"
                        :invalid="!!roomForm.errors.department_id"
                        class="w-full"
                    />
                    <small class="text-slate-400">
                        {{ roomForm.college_id ? 'Leave as "All Programs" to open this room to every program in the selected College.' : 'Select a College first to assign a specific Program.' }}
                    </small>
                    <small v-if="roomForm.errors.department_id" class="text-red-500">
                        {{ roomForm.errors.department_id }}
                    </small>
                </div>

                <!-- Capacity -->
                <div class="flex flex-col gap-1">
                    <label for="capacity" class="text-sm font-medium text-slate-700">
                        Capacity <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="capacity"
                        v-model="roomForm.capacity"
                        :min="1"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!roomForm.errors.capacity"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="roomForm.errors.capacity" class="text-red-500">
                        {{ roomForm.errors.capacity }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="status"
                        v-model="roomForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!roomForm.errors.status"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.status" class="text-red-500">
                        {{ roomForm.errors.status }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="roomForm.remarks"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this room"
                        :invalid="!!roomForm.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="roomForm.errors.remarks" class="text-red-500">
                        {{ roomForm.errors.remarks }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddRoom" />
                <Button
                    :label="editingRoom ? 'Update Room' : 'Save Room'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="roomForm.processing"
                    @click="onSaveRoom"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>