import { config, enableAutoUnmount } from '@vue/test-utils'

config.global.stubs = { teleport: true }

enableAutoUnmount(afterEach)
