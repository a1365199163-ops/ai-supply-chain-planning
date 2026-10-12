import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, ArrowDownRight, ArrowRight, Boxes, CheckCircle2, Clock3, MessageSquareText, RefreshCw, Send, Sparkles, Truck } from 'lucide-react';
import AppLayout from '../../layouts/AppLayout';
import '../../../css/light-planning.css';

const prompts = [
    '未来 30 天有哪些物料缺口？',
    '哪些工单需要优先关注交期？',
    '当前生产计划整体情况如何？',
];
const statusLabels = { PENDING: '待接单', ACCEPTED: '已接单', IN_PROGRESS: '生产中', BLOCKED: '受阻', PAUSED: '已暂停', CHANGE_HOLD: '变更暂停' };
const number = (value) => new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(Number(value || 0));

export default function AiPlanning({ planning, refreshedAt }) {
    const { props } = usePage();
    const [question, setQuestion] = useState('');
    const [reply, setReply] = useState(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');

    async function ask(value = question) {
        const text = value.trim();
        if (!text || sending) return;
        setQuestion(text);
        setSending(true);
        setError('');
        try {
            const response = await fetch('/admin/ai-planning/ask', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': props.csrf_token },
                body: JSON.stringify({ question: text, horizon: planning.period.days }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || '暂时无法生成建议，请稍后重试。');
            setReply(data);
        } catch (e) {
            setError(e.message || '请求失败，请稍后重试。');
        } finally {
            setSending(false);
        }
    }

    const metrics = planning.metrics;
    return <><Head title="AI 供应链计划 · 智造协同"/><div className="lp-dashboard lp-planning">
        <div className="lp-page-heading">
            <div><div className="lp-eyebrow">AI SUPPLY CHAIN / PLANNING COPILOT</div><h1>让物料需求与生产计划对得上。</h1><p>结合工单、BOM、库存和交期，识别供应缺口与计划关注项。</p></div>
            <div className="lp-plan-heading-actions"><div className="lp-horizon-select" aria-label="计划周期">{[[7, '周视图'], [30, '月视图'], [90, '季度']].map(([days, label]) => <button type="button" key={days} className={planning.period.days === days ? 'active' : ''} onClick={() => router.get('/admin/ai-planning', { horizon: days }, { preserveScroll: true, preserveState: true })}>{label}</button>)}</div><Link className="lp-button secondary" href="/admin/schedule">排产计划 <ArrowRight size={14}/></Link><button className="lp-button secondary" onClick={() => router.reload({ only: ['planning', 'refreshedAt'] })}><RefreshCw size={15}/>刷新</button></div>
        </div>

        <div className="lp-agent-flow" aria-label="计划助手执行流程">
            <div><Sparkles size={17}/><span><b>计划 Agent</b><small>可追溯建议</small></span></div><span className="lp-agent-step">需求识别</span><ArrowRight size={14}/><span className="lp-agent-step">MRP / 工单工具</span><ArrowRight size={14}/><span className="lp-agent-step">风险归因</span><ArrowRight size={14}/><span className="lp-agent-step">建议生成</span>
        </div>

        <div className="lp-metrics lp-plan-metrics">
            {[[<Boxes size={16}/>, '计划工单', metrics.planned_orders, `未来 ${planning.period.days} 天纳入 MRP`], [<AlertTriangle size={16}/>, '物料净缺口', metrics.shortage_materials, '按 BOM 与当前库存计算'], [<Clock3 size={16}/>, '交期关注', metrics.attention_orders, `其中逾期 ${metrics.overdue_orders} 张`], [<ArrowDownRight size={16}/>, '缺料总量', number(metrics.total_shortfall), '不同计量单位合计']].map(([Icon, title, value, caption], index) => <div className="lp-metric lp-plan-metric" key={title}><div><span className="lp-metric-title-icon">{Icon}{title}</span><span className="lp-metric-index">0{index + 1}</span></div><strong>{value}</strong><small>{caption}</small></div>)}
        </div>

        <div className="lp-plan-grid">
            <section className="lp-panel lp-agent-panel">
                <div className="lp-panel-heading"><div><span className="lp-eyebrow">ASK THE PLANNER</span><h2><MessageSquareText size={17}/>供应链计划助手</h2></div><span className="lp-live-label"><i/>{planning.model_available ? '模型工具调用已配置' : '本地规则模式'}</span></div>
                <div className="lp-assistant-body">
                    <div className="lp-assistant-intro"><span className="lp-assistant-avatar"><Sparkles size={18}/></span><div><b>你好，我可以帮你排查计划与供应风险。</b><p>选择一个问题，或直接输入你想了解的情况。</p></div></div>
                    <div className="lp-prompt-list">{prompts.map((prompt) => { const text = prompt.replace('30', String(planning.period.days)); return <button key={prompt} onClick={() => ask(text)} disabled={sending}>{text}<ArrowRight size={14}/></button>; })}</div>
                    <form className="lp-ask-form" onSubmit={(event) => { event.preventDefault(); ask(); }}><input aria-label="输入计划问题" value={question} onChange={(event) => setQuestion(event.target.value)} placeholder="例如：哪些工单会受物料短缺影响？" maxLength={500}/><button className="lp-button" type="submit" disabled={sending || question.trim().length < 2}>{sending ? '分析中…' : <><Send size={15}/>分析</>}</button></form>
                    {error && <p role="alert" className="lp-ask-error">{error}</p>}
                    {reply && <div className="lp-answer" aria-live="polite"><div className="lp-answer-heading"><span className="lp-assistant-avatar"><Sparkles size={16}/></span><div><b>{reply.title}</b><small>问题：{question} · {reply.mode === 'llm' ? '模型 + 业务工具' : '本地规则兜底'}</small></div></div><p>{reply.answer}</p>{reply.nodes?.length > 0 && <div className="lp-agent-trace">Agent 节点：{reply.nodes.join(' → ')}</div>}{reply.evidence?.length > 0 && <div className="lp-evidence"><strong>判断依据</strong>{reply.evidence.map((item, i) => <span key={i}><CheckCircle2 size={14}/>{item}</span>)}</div>}{reply.actions?.length > 0 && <div className="lp-answer-actions"><strong>建议下一步</strong><div>{reply.actions.map((item, i) => <span key={i}>{item}</span>)}</div></div>}</div>}
                </div>
                <div className="lp-panel-foot">当前模式：{planning.model_available ? '模型工具调用，可在服务不可用时回退规则流程' : '本地可解释规则流程；配置 OpenAI 兼容接口后启用模型工具调用'} · 只读建议，不自动改排程或下采购单。</div>
            </section>

            <section className="lp-panel lp-recommendations"><div className="lp-panel-heading"><div><span className="lp-eyebrow">PLANNING SIGNALS</span><h2>优先处理建议</h2></div><span className="lp-source-tag">数据更新于 {refreshedAt}</span></div><div className="lp-recommendation-list">{planning.recommendations.map((item, index) => <article className={`lp-recommendation ${item.level}`} key={`${item.title}-${index}`}><span className="lp-rec-index">0{index + 1}</span><div><b>{item.title}</b><p>{item.detail}</p><small>依据：{item.source}</small></div></article>)}</div><div className="lp-panel-foot">建议根据当前工单、物料库存和 BOM 计算</div></section>
        </div>

        <div className="lp-plan-grid lp-plan-lower">
            <section className="lp-panel"><div className="lp-panel-heading"><div><span className="lp-eyebrow">MATERIAL REQUIREMENTS</span><h2><Boxes size={17}/>物料缺口清单</h2></div><span className="lp-source-tag">{planning.period.start} — {planning.period.end}</span></div>{planning.shortages.length ? <div className="lp-table-wrap"><table className="lp-plan-table"><thead><tr><th>物料</th><th>需求</th><th>库存</th><th>净缺口</th><th>关联工单</th><th>供应商</th></tr></thead><tbody>{planning.shortages.map((item) => <tr key={item.material_id}><td><b>{item.code}</b><small>{item.name}</small></td><td>{number(item.required_qty)} {item.unit_of_measure}</td><td>{number(item.available_qty)} {item.unit_of_measure}</td><td><b className="lp-short-qty">{number(item.net_qty)} {item.unit_of_measure}</b></td><td>{item.related_work_orders?.join('、') || '—'}</td><td>{item.supplier_name || '未维护'}</td></tr>)}</tbody></table></div> : <div className="lp-plan-empty"><CheckCircle2 size={21}/><span>{metrics.planned_orders === 0 ? '暂无可计算的待排产需求。请先建立有交期的工单，并维护产品 BOM 与库存。' : '当前范围内没有计算出物料净缺口。'}</span><Link href="/admin/work-orders">查看工单 <ArrowRight size={14}/></Link></div>}<div className="lp-panel-foot">MRP 口径：未来 {planning.period.days} 天待接单 / 已接单工单，按 BOM 逐层展开并与当前库存净额</div></section>

            <section className="lp-panel"><div className="lp-panel-heading"><div><span className="lp-eyebrow">DELIVERY WATCH</span><h2><Truck size={17}/>交期关注工单</h2></div><span className="lp-source-tag">逾期 / 受阻 / 临期低进度</span></div>{planning.attention_orders.length ? <div className="lp-watch-list">{planning.attention_orders.slice(0, 8).map((order) => <Link href={`/admin/work-orders/${order.id}`} key={order.id} className="lp-watch-order"><div><b>{order.order_no}</b><span className={`lp-risk-badge ${order.is_overdue ? 'urgent' : order.is_blocked ? 'blocked' : ''}`}>{order.is_overdue ? '已逾期' : order.is_blocked ? statusLabels[order.status] : `${Math.max(0, order.days_until_due)} 天内到期`}</span></div><div className="lp-watch-progress"><span><i style={{ width: `${order.progress}%` }}/></span><small>{order.progress}% · {order.produced_qty}/{order.planned_qty}</small></div><small className="lp-watch-meta">交期 {order.due_date} · {order.line || '未分配产线'}</small></Link>)}</div> : <div className="lp-plan-empty"><CheckCircle2 size={21}/><span>当前没有命中交期关注规则的工单。</span></div>}<div className="lp-panel-foot">关注规则：已逾期、生产受阻，或 7 天内到期且完成进度低于 80%</div></section>
        </div>

        <div className="lp-planning-disclaimer"><Sparkles size={15}/><span>建议只用于辅助计划员判断。缺料数据依赖有效 BOM 与库存台账；交期关注基于工单状态和完成进度，不构成产能或到货预测。</span></div>
    </div></>;
}

AiPlanning.layout = page => <AppLayout>{page}</AppLayout>;
