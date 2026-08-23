<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import Button from 'primevue/button';

/**
 * "Request New Faculty" — the Dean/OIC/Assistant Dean path (spec
 * Section 3). Posts to scheduling.faculty-requests.store-creation,
 * which creates a Pending FacultyRequest, NOT an active Faculty
 * record. The College is always re-derived server-side from the
 * logged-in user's own assignment — this form never sends one.
 */
const props = defineProps({
    visible: { type: Boolean, default: false },
    nextFacultyId: { type: String, default: '' },
});

const emit = defineEmits(['update:visible']);

const form = useForm({
    faculty_id: '',
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    employment_type: null,
    email: '',
    contact_number: '',
    max_teaching_units: 21,
    reason: '',
});

const employmentTypeOptions = ['Full-time', 'Part-time'];

// Full-time faculty may be proposed up to 24 units; Part-time is capped
// at 18. Whenever the employment type changes, clamp any already-entered
// value down to the new ceiling so the form never submits an invalid one.
const maxUnitsForType = (type) => (type === 'Part-time' ? 18 : 24);

watch(
    () => form.employment_type,
    (type) => {
        const ceiling = maxUnitsForType(type);
        if (form.max_teaching_units > ceiling) {
            form.max_teaching_units = ceiling;
        }
    },
);

watch(
    () => props.visible,
    (visible) => {
        if (visible) {
            form.reset();
            form.clearErrors();
            form.faculty_id = props.nextFacultyId;
        }
    },
);

const UPPERCASE_FIELDS = ['faculty_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'contact_number'];
UPPERCASE_FIELDS.forEach((field) => {
    watch(
        () => form[field],
        (value) => {
            if (typeof value === 'string' && value !== value.toUpperCase()) {
                form[field] = value.toUpperCase();
            }
        },
    );
});

const close = () => emit('update:visible', false);

const submit = () => {
    form.post(route('scheduling.faculty-requests.store-creation'), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
};
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="(value) => emit('update:visible', value)"
        modal
        header="Request New Faculty"
        :style="{ width: '32rem' }"
    >
        <p class="text-sm text-slate-500 mb-4">
            This submits a request for Admin/Registrar review. The faculty member is not added to the roster
            until the request is approved. The College is automatically your own assigned college.
        </p>
        <form class="grid grid-cols-1 sm:grid-cols-2 gap-4" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Faculty ID *</label>
                <InputText v-model="form.faculty_id" />
                <small v-if="form.errors.faculty_id" class="text-red-500">{{ form.errors.faculty_id }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Employment Type *</label>
                <Select v-model="form.employment_type" :options="employmentTypeOptions" placeholder="Select type" />
                <small v-if="form.errors.employment_type" class="text-red-500">{{ form.errors.employment_type }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">First Name *</label>
                <InputText v-model="form.first_name" />
                <small v-if="form.errors.first_name" class="text-red-500">{{ form.errors.first_name }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Last Name *</label>
                <InputText v-model="form.last_name" />
                <small v-if="form.errors.last_name" class="text-red-500">{{ form.errors.last_name }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Middle Name</label>
                <InputText v-model="form.middle_name" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Suffix</label>
                <InputText v-model="form.suffix" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Proposed Max Units</label>
                <InputNumber v-model="form.max_teaching_units" :min="0" :max="maxUnitsForType(form.employment_type)" />
                <small class="text-slate-400">Up to {{ maxUnitsForType(form.employment_type) }} units for {{ form.employment_type || 'this type' }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Email</label>
                <InputText v-model="form.email" />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Contact Number</label>
                <InputText v-model="form.contact_number" />
            </div>
            <div class="flex flex-col gap-1 sm:col-span-2">
                <label class="text-sm font-medium">Reason / Remarks *</label>
                <Textarea v-model="form.reason" rows="3" autoResize placeholder="Why this faculty member is needed" />
                <small v-if="form.errors.reason" class="text-red-500">{{ form.errors.reason }}</small>
            </div>
        </form>
        <template #footer>
            <Button label="Cancel" severity="secondary" outlined @click="close" />
            <Button label="Submit Request" icon="pi pi-send" :loading="form.processing" @click="submit" />
        </template>
    </Dialog>
</template>