import {useEffect, useState} from 'react'
import {Col, Collapse, Layout, Row, Space, Tabs, Typography} from 'antd'
import React from "react";
import {CopyToClipboard} from 'react-copy-to-clipboard'
import logo from "./favalorologo.png"
import Tooltip from "@reach/tooltip"
import "@reach/tooltip/styles.css"
import './App.css'
import useFetch from "react-fetch-hook"
import LoadingSpinner from './LoadingSpinner/LoadingSpinner';

const {Panel} = Collapse
const {Header, Footer, Content} = Layout
const {TabPane} = Tabs
const {Text} = Typography
const FLOORS = ["Inicio", "Piso -1", "Piso 3", "Piso 5", "Piso 6", "Piso 7", "Piso 8", "Piso 9"]

var controller = undefined
var signal = undefined
var debugging = true


function getChromeVersion () {     
	var raw = navigator.userAgent.match(/Chrom(e|ium)\/([0-9]+)\./);
	return raw ? parseInt(raw[2], 10) : false;
}
const chromeVersion = getChromeVersion()

// Chequeo de credenciales. Si el usuario no está loggeado, lo redirecciona a login.html
async function checkLogin() {
  if (debugging) {
  console.log("Modo debugging")
  return
  }
  
	let response = await fetch(`http://192.168.15.168:8001/html/check_login.php`, {credentials: "same-origin", signal: signal})
	let login_respuesta = await response.text()
	if (login_respuesta == "no") {
		console.log("Credenciales no aprobadas")
		window.location = `http://192.168.15.168:8001/html/login.html`
	}
  if (login_respuesta == "si") { 
	console.log("Credenciales ok")
  }
}

checkLogin().catch(e => {
console.log(e)
})

function App() {	
	const [patients, setPatients] = useState([])
	const [activeFloor, setActiveFloor] = useState(0)
    const [isLoading, setIsLoading] = useState(true) //Spinner

  useEffect(() => {
	if (controller) {
		controller.abort()
	}
  }, [activeFloor])
  
// Asigna el controlador y la señar del sistema para abortar la consulta.
// Mejora el rendimiento cuando se cambia de piso antes de que el piso anterior esté completamente cargado 
// Nota: AbortController solo funciona en versiones nuevas de Chrome.

  useEffect(() => {
	  if (chromeVersion > 80) {
		controller = new AbortController()
		signal = controller.signal 
	  }
	  else {
		controller = undefined
		signal = undefined
	  }
	}, [activeFloor])

// Carga la ultima respuesta guardada desde get_cache.php 
  useEffect(() => {
    fetch( debugging ? `http://localhost/labchart/get_cache.php?piso=${activeFloor}` : `http://192.168.15.168:8001/html/labchart/get_cache.php?piso=${activeFloor}`, {credentials:'same-origin', signal:signal})
	  .then(response => response.json())
      .then(setPatients)
	  .catch(err => { 
	  console.log(err);})
  }, [activeFloor])
	
// Consulta los datos actualizados de labchart.php de un piso en específico (activeFloor)
  useEffect(() => {
    fetch(debugging ?  `http://localhost/labchart/labchart.php?piso=${activeFloor}`  : `http://192.168.15.168:8001/html/labchart/labchart.php?piso=${activeFloor}`, {
                method: 'get',
                signal: signal,
				credentials: 'same-origin'
            })
	  .then(response => {
		setIsLoading(false)
		// Cuando el cache fue generado recientemente (menos de 2 min), labchart.php devuelve "usar cache" en vez de un resultado. En ese caso, no actualiza.
		if (response === "usar cache")  {
			console.log("usar cache")
			return null
			}
		return response
	  })
	  .then(response => response.json())
	  .then(setPatients)
	  .catch(err => {
		if (err.name === 'AbortError') {
			console.log("Query abortada")
			return
		}
	  })
  }, [activeFloor])
  

// Actualiza la tabla principal cada vez que se cambia de piso.
  const handleChange = floor => {
    const parsedFloor = floor.split(' ')
    const floorNumber = parsedFloor.pop().split('-').pop()
    setActiveFloor(floorNumber)
	setIsLoading(true) // Activates spinner
  }

  return (
    <Layout>
      <Header style={{display: 'flex', alignItems: 'center',   justifyContent: 'space-between', height: '100px'}}>
        <img alt="Fundacion Favaloro" src={logo} style={{width: '180px'}} />
		{ isLoading && <LoadingSpinner />}
		<div style={{float : 'right'}}>
			<form align="right" name="form1" method="post" action="http://192.168.15.168:8001/html/logout.php">
			<input name="submit2" type="submit" id="submit2" value="Cerrar sesion" style={{float : 'right',  lineHeight : '25px'}}/> 
			</form>
		</div>
      </Header>
      <Content>
        <Tabs defaultActiveKey={"floor-0"} centered onChange={handleChange}>
          {FLOORS.map((floor, index) => (
            <TabPane key={`floor-${floor}`} tab={`${floor}`}>
              <Collapse defaultActiveKey={[]}>
                {patients.map(({HC, Nombre = '', Cama, Solicitud, timestamp, ...rest}) => (
                  <Panel
                    key={JSON.stringify(rest)}
                    header={`${Cama || " "} - ${Nombre} (HC:${HC}) - ${timestamp}`}>
                    <Row gutter={[32]}>
                      {Object.keys(rest).map(title => {
						if (title.toLowerCase() === 'text_corto') {
						return null
						}
						if (title.toLowerCase() === 'text_largo') {
						return null
						}
                        if (title.toLowerCase() === 'solicitud') {
                          return (
                            <Col>
                              <Space direction="vertical">
                                <h1 style={{textTransform: 'capitalize'}}>
                                  {title}
                                </h1>
                                <Text>N°: {rest[title]}</Text>
								<Text>Hora: {timestamp.slice(10)} </Text>
								<CopyToClipboard text={`${rest['text_corto']}`} >
									<button type="button">
									  Copiar compacto
									</button>								
								</CopyToClipboard>
								<CopyToClipboard text={`${rest['text_largo']}`} >
									<button>
									  Copiar completo
									</button>								
								</CopyToClipboard>


                              </Space>
                            </Col>
                          )
                        }

                        return (
                          <Col >
                            <Space direction="vertical">
                              <h1>{title}</h1>
                              {Object.keys(rest[title]).map(prop => (
								<Tooltip label={`${rest[title][prop].info}`}  
								style={rest[title][prop].info === "" ? {visibility:"collapsed"} : {
									fontSize: "100%",
									background: rest[title][prop].color,
									opacity: "95%",
									color: "white",
									//border: "1px solid #000",
									borderRadius: "4px",
								}}>
									<div>
										<Text style={{color: rest[title][prop].color}}>
											{`${rest[title][prop].nombre_estudio}: ${rest[title][prop].resultado} ${rest[title][prop].unidades}   `}
										</Text>
									</div>
								</Tooltip>
                              ))}
                            </Space>
                          </Col>
                        )
                      })}
                    </Row>
                  </Panel>
                ))}
              </Collapse>
            </TabPane>
          ))}
        </Tabs>
      </Content>
      <Footer>	  
		<div>Utilice la barra superior para elegir un piso. </div>
		<div><i>Tip: Si mantenés el mouse sobre un valor resaltado, podés obtener información adicional. </i></div>
	  </Footer>
    </Layout>
  )
}

export default App
