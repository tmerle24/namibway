{{-- Applies to every panel page via a HEAD_END render hook, so it also covers
     future resources without extra setup. --}}
<style>
    .fi-main {
        margin-left: 0;
        margin-right: 0;
    }

    .fi-header {
        position: fixed;
        width: 100%;
        overflow: hidden;
    }

    .fi-header + div {
        margin-top: 100px;
    }
</style>
