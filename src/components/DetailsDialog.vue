<template>
  <k-dialog
    class="k-trash-details-dialog"
    :cancel-button="false"
    :submit-button="submitButton"
    :visible="visible"
    @cancel="$emit('cancel')"
    @submit="$panel.dialog.close()"
  >
    <!-- large preview for image files and page covers; items
         without a preview show nothing extra here — the icon
         carries no information beyond the fields below. The frame
         takes the image's native ratio, so nothing is cropped or
         letterboxed; 3/2 only backstops unreadable dimensions -->
    <k-image-frame
      v-if="image && image.src"
      class="k-trash-details-preview"
      :src="image.src"
      back="pattern"
      :ratio="ratio || '3/2'"
    />
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
        v-if="postponeLabel"
        icon="clock"
        variant="filled"
        :disabled="!canRestore"
        :dialog="'trash/' + trashId + '/postpone'"
      >
        {{ postponeLabel }}
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
    // the row's panel image object; only its src is used here
    image: Object,
    // native aspect ratio of the preview ("W/H"), or null when
    // the dimensions could not be read server-side
    ratio: String,
    canRestore: Boolean,
    canDelete: Boolean,
    // label of the postpone action ("Keep longer"),
    // or null when the item cannot be postponed
    postponeLabel: String,
    // passed by the Panel's dialog portal; must be forwarded to
    // k-dialog explicitly — k-dialog only teleports into the portal
    // while its own `visible` prop is set
    visible: {
      type: Boolean,
      default: false
    }
  },
  // Vue 3 treats undeclared events as native listeners on the root
  // element; declaring `cancel` keeps the Panel's close listener on
  // the component event
  emits: ["cancel"],
  computed: {
    submitButton() {
      return { text: this.$t("close") };
    }
  }
};
</script>
