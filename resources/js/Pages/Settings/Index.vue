<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Button from 'primevue/button';
import Divider from 'primevue/divider';
import FloatLabel from 'primevue/floatlabel';
import Toast from 'primevue/toast';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';

const toast = useToast();
const page = usePage();

// Show a toast whenever the backend flashes a success/error message
// (e.g. right after "Manage Account" saves).
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

// Administrators already have a "Manage Account" tab on the Users page,
// so this tab is for everyone else — Registrar, Dean, OIC and Assistant
// Dean — to update their own profile and change their password.
const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));
const activeTab = ref('general');

/* ------------------------------------------------------------------ */
/* Manage Account tab (Registrar / Dean / OIC / Assistant Dean)        */
/* ------------------------------------------------------------------ */

const accountForm = useForm({
    first_name: page.props.auth?.user?.first_name ?? '',
    middle_name: page.props.auth?.user?.middle_name ?? '',
    last_name: page.props.auth?.user?.last_name ?? '',
    suffix: page.props.auth?.user?.suffix ?? '',
    email: page.props.auth?.user?.email ?? '',
    password: '',
    password_confirmation: '',
});

const onUpdateAccount = () => {
    accountForm.transform((data) => ({
        ...data,
        _method: 'put',
    })).post(route('account.update'), {
        preserveScroll: true,
        onSuccess: () => {
            accountForm.password = '';
            accountForm.password_confirmation = '';
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <Toast />

        <div class="max-w-3xl mx-auto w-full">
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Settings</h1>
                <p class="mt-1 text-slate-500">
                    Configure system preferences.
                </p>
            </div>

            <Tabs v-model:value="activeTab">
                <TabList>
                    <Tab value="general">General</Tab>
                    <Tab v-if="!isAdministrator" value="account">Manage Account</Tab>
                </TabList>

                <TabPanels>
                    <!-- General Tab -->
                    <TabPanel value="general">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
                            <template #content>
                                <p class="text-slate-500">
                                    General system preferences will appear here.
                                </p>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- Manage Account Tab (Registrar / Dean / OIC / Assistant Dean) -->
                    <TabPanel v-if="!isAdministrator" value="account">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm max-w-2xl">
                            <template #content>
                                <h2 class="text-lg font-bold text-[#1E293B] mb-1">Manage Account</h2>
                                <p class="text-sm text-slate-500 mb-5">
                                    Update your own profile and change your password.
                                </p>

                                <form class="pt-1" autocomplete="off" @submit.prevent="onUpdateAccount">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accFirstName"
                                                v-model="accountForm.first_name"
                                                class="w-full"
                                                autocomplete="off"
                                                :invalid="!!accountForm.errors.first_name"
                                            />
                                            <label for="accFirstName">First Name *</label>
                                        </FloatLabel>

                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accMiddleName"
                                                v-model="accountForm.middle_name"
                                                class="w-full"
                                                autocomplete="off"
                                            />
                                            <label for="accMiddleName">Middle Name</label>
                                        </FloatLabel>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accLastName"
                                                v-model="accountForm.last_name"
                                                class="w-full"
                                                autocomplete="off"
                                                :invalid="!!accountForm.errors.last_name"
                                            />
                                            <label for="accLastName">Last Name *</label>
                                        </FloatLabel>

                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accSuffix"
                                                v-model="accountForm.suffix"
                                                class="w-full"
                                                autocomplete="off"
                                            />
                                            <label for="accSuffix">Suffix</label>
                                        </FloatLabel>
                                    </div>

                                    <Divider class="!my-5" />

                                    <div class="grid grid-cols-1 gap-5">
                                        <FloatLabel variant="on">
                                            <InputText
                                                id="accEmail"
                                                v-model="accountForm.email"
                                                type="email"
                                                class="w-full"
                                                autocomplete="off"
                                                :invalid="!!accountForm.errors.email"
                                            />
                                            <label for="accEmail">Email *</label>
                                        </FloatLabel>
                                        <small v-if="accountForm.errors.email" class="text-red-500 -mt-4">{{ accountForm.errors.email }}</small>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5">
                                        <FloatLabel variant="on">
                                            <Password
                                                id="accPassword"
                                                v-model="accountForm.password"
                                                toggleMask
                                                :feedback="true"
                                                inputClass="w-full"
                                                class="w-full"
                                                autocomplete="new-password"
                                                :invalid="!!accountForm.errors.password"
                                            />
                                            <label for="accPassword">New Password</label>
                                        </FloatLabel>

                                        <FloatLabel variant="on">
                                            <Password
                                                id="accPasswordConfirm"
                                                v-model="accountForm.password_confirmation"
                                                toggleMask
                                                :feedback="false"
                                                inputClass="w-full"
                                                class="w-full"
                                                autocomplete="new-password"
                                                :invalid="!!accountForm.errors.password"
                                            />
                                            <label for="accPasswordConfirm">Confirm New Password</label>
                                        </FloatLabel>
                                    </div>
                                    <small v-if="accountForm.errors.password" class="text-red-500">{{ accountForm.errors.password }}</small>
                                    <p class="text-xs text-slate-400 mt-1">Leave blank to keep your current password.</p>

                                    <div class="flex justify-end mt-6">
                                        <Button
                                            type="submit"
                                            label="Save Changes"
                                            icon="pi pi-check"
                                            :loading="accountForm.processing"
                                        />
                                    </div>
                                </form>
                            </template>
                        </Card>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>
    </AppLayout>
</template>