import { mount } from '@vue/test-utils'
import PieChart from '../../../src/components/PieChart.vue'

const partes = [{ val: 11, color: '#00693C' }, { val: 12, color: '#7A9486' }, { val: 0, color: '#D6001C' }, { val: 1, color: '#00A651' }]

describe('PieChart', () => {
  it('desenha cada fatia como setor preenchido (nada de traço grosso com stroke-dasharray, que sobrepõe no navegador)', () => {
    const w = mount(PieChart, { props: { parts: partes, label: 'status' } })
    const paths = w.findAll('path')
    expect(paths.map((p) => p.attributes('fill'))).toEqual(['#00693C', '#7A9486', '#00A651'])
    expect(w.html()).not.toContain('stroke-dasharray')
    paths.forEach((p) => expect(p.attributes('d')).toMatch(/^M66 66 L[\d.]+ [\d.]+ A66 66 0 [01] 1 [\d.]+ [\d.]+ Z$/))
  })
  it('as fatias fecham o círculo: ângulos somam 360° e fatia maior que meia volta usa o arco grande', () => {
    const w = mount(PieChart, { props: { parts: [{ val: 3, color: '#111' }, { val: 1, color: '#222' }] } })
    const [a, b] = w.findAll('path').map((p) => p.attributes('d'))
    expect(a).toContain('A66 66 0 1 1')
    expect(b).toContain('A66 66 0 0 1')
    const fim = a.match(/A66 66 0 1 1 ([\d.-]+) ([\d.-]+) Z/).slice(1, 3).map(Number)
    const ini = b.match(/L([\d.-]+) ([\d.-]+) A/).slice(1, 3).map(Number)
    expect(ini[0]).toBeCloseTo(fim[0], 2); expect(ini[1]).toBeCloseTo(fim[1], 2)
  })
  it('uma fatia só (100%) vira círculo cheio e sem dados vira círculo cinza', () => {
    const um = mount(PieChart, { props: { parts: [{ val: 5, color: '#00693C' }, { val: 0, color: '#999' }] } })
    expect(um.findAll('path')).toHaveLength(0)
    expect(um.find('circle[fill="#00693C"]').exists()).toBe(true)
    const vazio = mount(PieChart, { props: { parts: [{ val: 0, color: '#999' }] } })
    expect(vazio.find('circle[fill="#EDF1EE"]').exists()).toBe(true)
  })
})
