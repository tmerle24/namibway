<script setup lang="ts">
import logoDark from '../../images/logo-dark.png';
import logoLight from '../../images/logo-light.png';
</script>

<template>
    <img :src="logoDark" alt="NamibWay" class="h-7 dark:hidden" />
    <img :src="logoLight" alt="NamibWay" class="hidden h-7 dark:block" />
</template>
