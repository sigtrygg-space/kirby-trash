<template>
  <k-dialog
    class="k-trash-details-dialog"
    :cancel-button="false"
    :submit-button="submitButton"
    :visible="visible"
    @cancel="$emit('cancel')"
    @submit="$panel.dialog.close()"
  >
    <dl>
      <div v-for="field in fields" :key="field.label">
        <dt>{{ field.label }}</dt>
        <dd>{{ field.value }}</dd>
      </div>
    </dl>
    <k-button-group
      v-if="canRestore || canDelete"
      class="k-trash-details-actions"
    >
      <k-button
        icon="undo"
        variant="filled"
        :disabled="!canRestore"
        :dialog="'trash/' + trashId + '/restore'"
      >
        {{ $t("sigtrygg-space.kirby-trash.restore") }}
      </k-button>
      <k-button
        icon="trash"
        variant="filled"
        theme="negative"
        :disabled="!canDelete"
        :dialog="'trash/' + trashId + '/delete'"
      >
        {{ $t("sigtrygg-space.kirby-trash.delete") }}
      </k-button>
    </k-button-group>
  </k-dialog>
</template>

<script>
export default {
  props: {
    fields: {
      type: Array,
      default: () => []
    },
    trashId: String,
    canRestore: Boolean,
    canDelete: Boolean,
    // passed by the panel's dialog island; must be forwarded to
    // k-dialog explicitly — Vue 2 attribute fallthrough only sets
    // it as a DOM attribute, not as the k-dialog prop, and without
    // it k-dialog never teleports into the dialog portal
    visible: {
      type: Boolean,
      default: false
    }
  },
  computed: {
    submitButton() {
      return { text: this.$t("close") };
    }
  }
};
</script>
