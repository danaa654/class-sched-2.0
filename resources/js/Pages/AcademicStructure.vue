<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Toolbar from 'primevue/toolbar';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

// Empty datasets — no CRUD / no backend wiring yet.
const colleges = [];
const departments = [];
const programs = [];
const subjects = [];
const teachers = [];
</script>

<template>
    <Head title="Academic Structure" />

    <AppLayout>
        <template #header>
            <span class="text-lg font-semibold text-[#1E293B]">Academic Structure</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-[#1E293B]">Academic Structure</h1>
                <p class="mt-1 text-slate-500">
                    Manage the institution's academic hierarchy including Colleges, Departments, Programs, Subjects, and Teachers.
                </p>
            </div>

            <Tabs value="colleges">
                <TabList>
                    <Tab value="colleges">Colleges</Tab>
                    <Tab value="departments">Departments</Tab>
                    <Tab value="programs">Programs</Tab>
                    <Tab value="subjects">Subjects</Tab>
                    <Tab value="teachers">Teachers</Tab>
                </TabList>

                <TabPanels>
                    <!-- Colleges -->
                    <TabPanel value="colleges">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #title>
                                <span class="text-lg font-bold text-[#1E293B]">Colleges</span>
                            </template>
                            <template #subtitle>
                                <span class="text-sm text-slate-500">Manage all colleges.</span>
                            </template>
                            <template #content>
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                                    <template #end>
                                        <Button label="Add College" icon="pi pi-plus" />
                                    </template>
                                </Toolbar>

                                <DataTable :value="colleges" class="rounded-xl overflow-hidden" stripedRows>
                                    <template #empty>
                                        <div class="text-center py-8 text-slate-400">
                                            No colleges found.
                                        </div>
                                    </template>
                                    <Column field="code" header="Code" />
                                    <Column field="name" header="College Name" />
                                    <Column field="dean" header="Dean" />
                                    <Column field="status" header="Status">
                                        <template #body="{ data }">
                                            <Tag :value="data.status" :severity="data.status === 'Active' ? 'success' : 'danger'" />
                                        </template>
                                    </Column>
                                    <Column header="Actions" style="width: 8rem">
                                        <template #body>
                                            <div class="flex gap-2">
                                                <Button icon="pi pi-pencil" text rounded severity="secondary" size="small" />
                                                <Button icon="pi pi-trash" text rounded severity="danger" size="small" />
                                            </div>
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- Departments -->
                    <TabPanel value="departments">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #title>
                                <span class="text-lg font-bold text-[#1E293B]">Departments</span>
                            </template>
                            <template #subtitle>
                                <span class="text-sm text-slate-500">Manage all departments.</span>
                            </template>
                            <template #content>
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                                    <template #end>
                                        <Button label="Add Department" icon="pi pi-plus" />
                                    </template>
                                </Toolbar>

                                <DataTable :value="departments" class="rounded-xl overflow-hidden" stripedRows>
                                    <template #empty>
                                        <div class="text-center py-8 text-slate-400">
                                            No departments found.
                                        </div>
                                    </template>
                                    <Column field="code" header="Department Code" />
                                    <Column field="name" header="Department Name" />
                                    <Column field="college" header="College" />
                                    <Column field="head" header="Head" />
                                    <Column field="status" header="Status">
                                        <template #body="{ data }">
                                            <Tag :value="data.status" :severity="data.status === 'Active' ? 'success' : 'danger'" />
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- Programs -->
                    <TabPanel value="programs">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #title>
                                <span class="text-lg font-bold text-[#1E293B]">Programs</span>
                            </template>
                            <template #subtitle>
                                <span class="text-sm text-slate-500">Manage all programs.</span>
                            </template>
                            <template #content>
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                                    <template #end>
                                        <Button label="Add Program" icon="pi pi-plus" />
                                    </template>
                                </Toolbar>

                                <DataTable :value="programs" class="rounded-xl overflow-hidden" stripedRows>
                                    <template #empty>
                                        <div class="text-center py-8 text-slate-400">
                                            No programs found.
                                        </div>
                                    </template>
                                    <Column field="code" header="Program Code" />
                                    <Column field="name" header="Program Name" />
                                    <Column field="department" header="Department" />
                                    <Column field="years" header="Years" />
                                    <Column field="status" header="Status">
                                        <template #body="{ data }">
                                            <Tag :value="data.status" :severity="data.status === 'Active' ? 'success' : 'danger'" />
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- Subjects -->
                    <TabPanel value="subjects">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #title>
                                <span class="text-lg font-bold text-[#1E293B]">Subjects</span>
                            </template>
                            <template #subtitle>
                                <span class="text-sm text-slate-500">Manage all subjects.</span>
                            </template>
                            <template #content>
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                                    <template #end>
                                        <Button label="Add Subject" icon="pi pi-plus" />
                                    </template>
                                </Toolbar>

                                <DataTable :value="subjects" class="rounded-xl overflow-hidden" stripedRows>
                                    <template #empty>
                                        <div class="text-center py-8 text-slate-400">
                                            No subjects found.
                                        </div>
                                    </template>
                                    <Column field="code" header="Code" />
                                    <Column field="name" header="Subject" />
                                    <Column field="units" header="Units" />
                                    <Column field="type" header="Type" />
                                    <Column field="program" header="Program" />
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>

                    <!-- Teachers -->
                    <TabPanel value="teachers">
                        <Card class="!rounded-2xl border border-slate-100 shadow-sm mt-4">
                            <template #title>
                                <span class="text-lg font-bold text-[#1E293B]">Teachers</span>
                            </template>
                            <template #subtitle>
                                <span class="text-sm text-slate-500">Manage all teachers.</span>
                            </template>
                            <template #content>
                                <Toolbar class="!bg-transparent !border-0 !px-0 !pt-0 !pb-4">
                                    <template #end>
                                        <Button label="Add Teacher" icon="pi pi-plus" />
                                    </template>
                                </Toolbar>

                                <DataTable :value="teachers" class="rounded-xl overflow-hidden" stripedRows>
                                    <template #empty>
                                        <div class="text-center py-8 text-slate-400">
                                            No teachers found.
                                        </div>
                                    </template>
                                    <Column field="name" header="Teacher" />
                                    <Column field="department" header="Department" />
                                    <Column field="specialization" header="Specialization" />
                                    <Column field="status" header="Status">
                                        <template #body="{ data }">
                                            <Tag :value="data.status" :severity="data.status === 'Active' ? 'success' : 'danger'" />
                                        </template>
                                    </Column>
                                </DataTable>
                            </template>
                        </Card>
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>
    </AppLayout>
</template>