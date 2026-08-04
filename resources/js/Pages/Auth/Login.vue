<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Checkbox from 'primevue/checkbox';
import Button from 'primevue/button';
import Message from 'primevue/message';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Login" />

    <div class="min-h-screen flex flex-col lg:flex-row bg-[#F8FAFC]">
        <!-- Left: Brand panel -->
        <div class="lg:w-1/2 flex items-center justify-center px-6 lg:px-16 py-16 lg:py-0">
            <div class="max-w-md">
                <h1 class="text-4xl sm:text-5xl font-extrabold text-[#1E293B] leading-tight">
                    CLASSLY
                </h1>
                <p class="mt-2 text-xl sm:text-2xl font-semibold text-[#2563EB]">
                    Class Scheduling and Management System
                </p>
                <p class="mt-6 text-lg text-slate-600">
                    A centralized platform for managing class schedules, teachers, rooms, and academic planning.
                </p>
            </div>
        </div>

        <!-- Right: Login form -->
        <div class="lg:w-1/2 flex items-center justify-center px-6 lg:px-16 py-16 bg-white">
            <Card class="w-full max-w-md shadow-lg border border-slate-100 !rounded-2xl">
                <template #title>
                    <span class="text-2xl font-bold text-[#1E293B]">Welcome back</span>
                    <p class="mt-1 text-sm font-normal text-slate-500">
                        Sign in to access your CLASSLY dashboard.
                    </p>
                </template>

                <template #content>
                    <form class="mt-4 flex flex-col gap-5" @submit.prevent="submit">
                        <Message v-if="form.errors.email" severity="error" :closable="false">
                            {{ form.errors.email }}
                        </Message>

                        <div class="flex flex-col gap-2">
                            <label for="email" class="text-sm font-medium text-[#1E293B]">Email</label>
                            <InputText
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                autofocus
                                class="w-full"
                                :invalid="!!form.errors.email"
                                placeholder="you@classly.local"
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <label for="password" class="text-sm font-medium text-[#1E293B]">Password</label>
                            <Password
                                id="password"
                                v-model="form.password"
                                :feedback="false"
                                toggleMask
                                autocomplete="current-password"
                                inputClass="w-full"
                                class="w-full"
                                :invalid="!!form.errors.password"
                                placeholder="••••••••"
                            />
                            <small v-if="form.errors.password" class="text-red-500">
                                {{ form.errors.password }}
                            </small>
                        </div>

                        <div class="flex items-center gap-2">
                            <Checkbox v-model="form.remember" inputId="remember" binary />
                            <label for="remember" class="text-sm text-slate-600">Remember me</label>
                        </div>

                        <Button
                            type="submit"
                            label="Login"
                            class="w-full !bg-[#2563EB] !border-[#2563EB]"
                            size="large"
                            :loading="form.processing"
                        />
                    </form>
                </template>
            </Card>
        </div>
    </div>
</template>