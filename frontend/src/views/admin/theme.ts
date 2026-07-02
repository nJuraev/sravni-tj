import type { GlobalThemeOverrides } from 'naive-ui'

/** Тема админки Naive UI под палитру eskhata (Primary Blue #0050C8). */
export const adminThemeOverrides: GlobalThemeOverrides = {
  common: {
    primaryColor: '#0050C8',
    primaryColorHover: '#0041A3',
    primaryColorPressed: '#003585',
    primaryColorSuppl: '#0050C8',
    infoColor: '#0050C8',
    borderRadius: '8px',
    fontSize: '14px',
  },
  Menu: {
    itemColorActive: 'rgba(0, 80, 200, 0.1)',
    itemColorActiveHover: 'rgba(0, 80, 200, 0.14)',
    itemTextColorActive: '#0050C8',
    itemIconColorActive: '#0050C8',
  },
}
