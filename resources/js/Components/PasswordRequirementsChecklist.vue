<script setup>
import { computed } from 'vue';

/**
 * Live checklist shown under the New Password field wherever a user
 * sets their own password (Settings > Manage Account for Registrar/
 * Dean/OIC/Assistant Dean, and the Manage Account tab on User
 * Management for Administrator). Each bullet lights up green the
 * moment the typed password satisfies it — mirrors the same rules
 * PasswordPolicyService::rules() enforces server-side, read from the
 * `policy` prop (PasswordPolicyService::requirements()) so the two
 * never drift out of sync.
 */
const props = defineProps({
    password: { type: String, default: '' },
    policy: {
        type: Object,
        default: () => ({ minLength: 8, requireUppercase: false, requireNumber: false, requireSymbol: false }),
    },
    isDark: { type: Boolean, default: false },
});

const checks = computed(() => {
    const pwd = props.password ?? '';
    const minLength = props.policy?.minLength ?? 8;

    const list = [
        {
            key: 'length',
            label: `At least ${minLength} characters`,
            met: pwd.length >= minLength,
        },
    ];

    if (props.policy?.requireUppercase) {
        list.push({ key: 'uppercase', label: 'A capital letter', met: /[A-Z]/.test(pwd) });
    }
    if (props.policy?.requireNumber) {
        list.push({ key: 'number', label: 'A number', met: /[0-9]/.test(pwd) });
    }
    if (props.policy?.requireSymbol) {
        list.push({ key: 'symbol', label: 'At least 1 symbol', met: /[^A-Za-z0-9]/.test(pwd) });
    }

    return list;
});
</script>

<template>
    <div class="password-checklist mt-2">
        <span class="text-xs font-medium" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
            Password must contain:
        </span>
        <ul class="mt-1 flex flex-col gap-1">
        <li
            v-for="check in checks"
            :key="check.key"
            class="flex items-center gap-2 text-xs transition-colors duration-150"
            :class="check.met
                ? (isDark ? 'text-emerald-400' : 'text-emerald-600')
                : (isDark ? 'text-slate-500' : 'text-slate-400')"
        >
            <i
                class="pi text-[11px]"
                :class="check.met ? 'pi-check-circle' : 'pi-circle-off'"
            ></i>
            <span>{{ check.label }}</span>
        </li>
        </ul>
    </div>
</template>